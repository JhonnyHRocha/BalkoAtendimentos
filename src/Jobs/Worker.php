<?php
declare(strict_types=1);
namespace App\Jobs;

use App\Clients\ApiException;
use App\Clients\ClikChatClient;
use App\Config\Config;
use App\Models\Store;
use App\Services\AtendimentoService;
use PDO;

final class Worker
{
    public function __construct(
        private Store $store,
        private AtendimentoService $service,
        private ClikChatClient $clik,
        private Config $config = new Config([]),
    ) {}

    public function once(?int $now = null): bool
    {
        $now ??= time();
        $lock = fopen($this->store->path.'.worker.lock', 'c');
        if ($lock === false) { throw new \RuntimeException('Lock indisponível'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
        try {
            $this->recover($now);
            $input = $this->input($now);
            $task = $this->background($now);
            $output = $this->output($now);
            return $input || $task || $output;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function input(int $now): bool
    {
        $debounce = max(0, (int)$this->config->get('DEBOUNCE_SECONDS', '1'));
        $s = $this->store->db->prepare('SELECT i.* FROM inbox i WHERE i.done=0
            AND NOT EXISTS (SELECT 1 FROM inbox earlier WHERE earlier.conversation=i.conversation AND earlier.done=0 AND earlier.id<i.id)
            AND (SELECT MAX(received_at) FROM inbox recent WHERE recent.conversation=i.conversation AND recent.done=0)<=CAST(? AS INTEGER)
            ORDER BY i.id LIMIT 1');
        $s->execute([$now-$debounce]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return false; }
        $m = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        $c = $this->store->load($m['key']);
        $ids = [(int)$row['id']];
        if (in_array($c['state'], ['collect','payment_collect'], true) && $this->canGroup($m)) {
            $s = $this->store->db->prepare('SELECT id,data FROM inbox WHERE done=0 AND conversation=? AND id>? ORDER BY id LIMIT 20');
            $s->execute([$m['key'], $row['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $next = json_decode($entry['data'], true, 512, JSON_THROW_ON_ERROR);
                if (!$this->canGroup($next) || strlen($m['text'])+strlen($next['text'])>4000) { break; }
                $m['text'] .= "\n".$next['text'];
                $ids[] = (int)$entry['id'];
            }
        }
        try { $text = $this->service->process($m, $c, $now); }
        catch (ApiException $e) {
            $c['last_error'] = $e->diagnostic();
            if ($c['state'] === 'simulation' && $e->temporary()) {
                $c['state'] = 'simulation_retry';
                $this->store->schedule($m['key'], 'simulation', $now + max($this->service->interval('simulation'), $e->retryAfter ?? 0), true);
                $text = 'A consulta está temporariamente indisponível. Vamos tentar novamente automaticamente e avisar por aqui.';
            } else {
                $text = $this->service->human($m['key'], $c, 'integration_failure', $e->diagnostic());
            }
        } catch (\Throwable $e) {
            self::log('flow_error', (int)$row['id'], $e);
            $text = $this->service->human($m['key'], $c, 'flow_error', ['type'=>get_class($e)]);
        }
        $this->store->finish($ids, $m, $c, $text);
        return true;
    }

    private function canGroup(array $message): bool
    {
        $text = $message['text'];
        return !preg_match('/audio|ptt|image|video|document/i', $message['media']) &&
            !\App\Services\Text::human($text) && !\App\Services\Text::no($text) &&
            !\App\Services\Text::yes($text) && !\App\Services\CustomerQuestions::detects($text) &&
            !in_array(\App\Services\Text::normalize($text), ['corrigir','confirmar','confirmo','link','status'], true);
    }
    private function recover(int $now): void
    {
        $rows = $this->store->db->query("SELECT id,data FROM conversations WHERE json_extract(data,'$.state') IN ('submitting','review')
            OR (json_extract(data,'$.uuid') IS NOT NULL AND json_extract(data,'$.state')!='finished')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $c = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if (!isset($c['route'])) {
                $s = $this->store->db->prepare('SELECT data FROM inbox WHERE conversation=? ORDER BY id DESC LIMIT 1');
                $s->execute([$row['id']]);
                $m = $s->fetchColumn();
                if ($m !== false) {
                    $m = json_decode($m, true, 512, JSON_THROW_ON_ERROR);
                    $c['route'] = array_intersect_key($m, array_flip(['key','number','company','channel']));
                    $this->store->save($row['id'], $c);
                } else {
                    $this->store->case($row['id'], 'missing_route', ['uuid'=>$c['uuid'] ?? null]);
                }
            }
            if ($c['state'] === 'submitting') {
                $this->store->transaction(function () use ($row, &$c) {
                    $this->service->uncertain($row['id'], $c, ['code'=>'interrupted_registration']);
                    if (isset($c['route'])) { $this->store->reply($c['route'], 'O cadastro foi interrompido e precisa de conferência. A pendência foi registrada para a equipe, sem nova tentativa automática.'); }
                });
                continue;
            }
            if ($c['state'] === 'review') {
                $s = $this->store->db->prepare('SELECT COUNT(*) FROM human_cases WHERE conversation=? AND kind="registration_uncertain" AND status="open"');
                $s->execute([$row['id']]);
                if (!(int)$s->fetchColumn()) { $this->service->uncertain($row['id'], $c, $c['registration_error'] ?? ['code'=>'legacy_review']); }
                continue;
            }
            if (!isset($c['route'])) { continue; }
            $this->store->schedule($row['id'], 'link', $now);
            $this->store->schedule($row['id'], 'status', $now + $this->service->interval('status'));
        }
    }
    private function background(int $now): bool
    {
        $s = $this->store->db->prepare('SELECT * FROM tasks WHERE done=0 AND available<=? ORDER BY available,id LIMIT 1');
        $s->execute([$now]);
        $task = $s->fetch(PDO::FETCH_ASSOC);
        if (!$task) { return false; }
        $c = $this->store->load($task['conversation']);
        $attempts = (int)$task['attempts'] + 1;
        $max = max(1, (int)$this->config->get(strtoupper($task['kind']).'_MAX_ATTEMPTS', $task['kind'] === 'status' ? '288' : ($task['kind'] === 'link' ? '120' : '5')));
        // Reserva antes da chamada: uma queda também consome uma tentativa.
        $this->store->db->prepare('UPDATE tasks SET attempts=? WHERE id=?')->execute([$attempts, $task['id']]);
        $error = null;
        try {
            $result = $attempts > $max ? ['again'=>true,'text'=>null] : $this->service->task($task['kind'], $c, $now);
        } catch (ApiException $e) {
            $error = $e;
            $c['last_error'] = $e->diagnostic();
            if ($task['kind'] === 'simulation' && $e->temporary()) { $c['state'] = 'simulation_retry'; }
            $result = ['again'=>true,'text'=>null];
        } catch (\Throwable $e) {
            self::log('task_error', (int)$task['id'], $e);
            $error = new ApiException('UY3', 400, ['type'=>get_class($e)]);
            $result = ['again'=>true,'text'=>null];
        }
        $failed = $result['again'] && ($attempts >= $max || ($error !== null && !$error->temporary()));
        $this->store->transaction(function () use ($task, $c, $attempts, $now, $result, $failed, $error) {
            if ($failed) {
                if ($task['kind'] === 'simulation' && !isset($c['uuid'])) { $c['state'] = 'simulation_failed'; }
                $this->store->case($task['conversation'], $task['kind'].'_exhausted', [
                    'attempts'=>$attempts,'uuid'=>$c['uuid'] ?? null,'error'=>$error?->diagnostic(),
                ]);
                if (isset($c['route'])) { $this->store->reply($c['route'], 'Não foi possível concluir a consulta de '.$task['kind'].'. A equipe recebeu uma pendência para verificar.'); }
            } elseif ($result['text'] !== null && isset($c['route'])) {
                $this->store->reply($c['route'] + ['buttons'=>\App\Services\ConversationMessages::buttons($c)], $result['text']);
            }
            if (($c['proposal_status']['revision'] ?? false) && $c['state'] === 'payment_collect') {
                $this->store->case($task['conversation'], 'payment_revision', [
                    'uuid'=>$c['uuid'],'ready'=>false,'status'=>$c['proposal_status'],
                ]);
            }
            if ($c['state'] === 'finished') {
                $this->store->stopTasks($task['conversation']);
                foreach (['payment_revision','link_exhausted','status_exhausted'] as $kind) {
                    $this->store->resolveKind($task['conversation'], $kind, 'Proposta finalizada');
                }
            }
            if (!$failed && !$result['again']) {
                $this->store->resolveKind($task['conversation'], $task['kind'].'_exhausted', 'Consulta concluída');
            }
            $this->store->save($task['conversation'], $c);
            $available = $now + max($this->service->interval($task['kind']), $error?->retryAfter ?? 0);
            $this->store->db->prepare('UPDATE tasks SET done=?,available=? WHERE id=?')
                ->execute([$failed ? 2 : ($result['again'] ? 0 : 1), $available, $task['id']]);
        });
        return true;
    }

    private function output(int $now): bool
    {
        $out = $this->store->nextOutput($now);
        if ($out === null) { return false; }
        $attempts = (int)$out['attempts'] + 1;
        $this->store->db->prepare('UPDATE outbox SET attempts=? WHERE id=?')->execute([$attempts, $out['id']]);
        $max = max(1, (int)$this->config->get('OUTBOX_MAX_ATTEMPTS', '5'));
        try {
            if ($attempts > $max) { throw new ApiException('CLIKCHAT', 400, ['code'=>'attempts_exhausted']); }
            $this->clik->send(json_decode($out['data'], true, 512, JSON_THROW_ON_ERROR), $out['text']);
            $this->store->db->prepare('UPDATE outbox SET done=1 WHERE id=?')->execute([$out['id']]);
        } catch (\Throwable $e) {
            self::log('delivery_error', (int)$out['id'], $e);
            $temporary = $e instanceof ApiException && $e->temporary();
            $dead = !$temporary || $attempts >= $max;
            $delay = max(min(3600, 5 * (2 ** min(9, $attempts - 1))), $e instanceof ApiException ? ($e->retryAfter ?? 0) : 0);
            $this->store->transaction(function () use ($out, $now, $delay, $dead, $e, $attempts) {
                $this->store->db->prepare('UPDATE outbox SET done=?,available=? WHERE id=?')->execute([$dead ? 2 : 0, $now+$delay, $out['id']]);
                if ($dead) {
                    $this->store->case($out['conversation'], 'delivery_failed', [
                        'outbox_id'=>(int)$out['id'],'attempts'=>$attempts,
                        'error'=>$e instanceof ApiException ? $e->diagnostic() : ['type'=>get_class($e)],
                    ]);
                }
            });
        }
        return true;
    }

    private static function log(string $event, int $job, \Throwable $e): void
    {
        error_log(json_encode(['event'=>$event,'job'=>$job,'type'=>get_class($e)], JSON_THROW_ON_ERROR));
    }
}
