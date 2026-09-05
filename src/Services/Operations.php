<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Store;
use PDO;

final class Operations
{
    public function __construct(private Store $store) {}

    public function pending(): array
    {
        return $this->store->db->query('SELECT id,conversation,kind,status,created,updated FROM human_cases WHERE status="open" ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function show(int $id): array
    {
        $s = $this->store->db->prepare('SELECT * FROM human_cases WHERE id=?');
        $s->execute([$id]);
        $case = $s->fetch(PDO::FETCH_ASSOC);
        if (!$case) { throw new \InvalidArgumentException('Pendência não encontrada'); }
        $case['details'] = json_decode($case['details'], true, 512, JSON_THROW_ON_ERROR);
        $case['conversation_data'] = $this->store->load($case['conversation']);
        $s = $this->store->db->prepare('SELECT id,data FROM inbox WHERE conversation=? ORDER BY id DESC LIMIT 30');
        $s->execute([$case['conversation']]);
        $case['recent_messages'] = array_map(static fn($r) => ['id'=>$r['id'],'message'=>json_decode($r['data'],true,512,JSON_THROW_ON_ERROR)], $s->fetchAll(PDO::FETCH_ASSOC));
        return $case;
    }

    public function reply(int $id, string $text): void
    {
        if (trim($text) === '' || strlen($text) > 4000) { throw new \InvalidArgumentException('Texto inválido'); }
        $case = $this->show($id);
        $route = $case['conversation_data']['route'] ?? null;
        if ($route === null) { throw new \RuntimeException('Conversa sem rota de envio'); }
        $this->store->transaction(function () use ($id, $route, $text) {
            $this->store->reply($route, $text);
            $this->event($id, 'reply_queued');
        });
    }

    /** Exige justificativa operacional; nenhuma ação chama APIs bancárias. */
    public function resolve(int $id, string $action, string $evidence, ?string $uuid = null): void
    {
        if (trim($evidence) === '') { throw new \InvalidArgumentException('Informe a evidência da conferência'); }
        $case = $this->show($id);
        if ($case['status'] !== 'open') { throw new \InvalidArgumentException('Pendência já resolvida'); }
        $c = $case['conversation_data'];
        $key = $case['conversation'];
        $this->store->transaction(function () use ($id, $case, $action, $evidence, $uuid, $key, $c) {
            if ($action === 'associate' || $action === 'not-created') {
                if ($case['kind'] !== 'registration_uncertain' || isset($c['uuid'])) {
                    throw new \InvalidArgumentException('Ação reservada ao cadastro incerto sem UUID');
                }
                if ($action === 'associate') {
                    if ($uuid === null || !preg_match('/^[a-z0-9_-]{1,200}$/i', $uuid)) { throw new \InvalidArgumentException('UUID inválido'); }
                    $c['uuid'] = $uuid;
                    $c['state'] = 'submitted';
                    $this->store->schedule($key, 'link', time(), true);
                    $this->store->schedule($key, 'status', time(), true);
                } else {
                    $c['state'] = 'confirm';
                }
            } elseif (in_array($action, ['retry-delivery','skip-delivery'], true)) {
                if ($case['kind'] !== 'delivery_failed') { throw new \InvalidArgumentException('Não é falha de entrega'); }
                $s = $this->store->db->prepare('UPDATE outbox SET done=?,attempts=0,available=0 WHERE id=? AND conversation=? AND done=2');
                $s->execute([$action === 'retry-delivery' ? 0 : 3, $case['details']['outbox_id'], $key]);
                if ($s->rowCount() !== 1) { throw new \RuntimeException('Saída não está pendente de conferência'); }
            } elseif ($action === 'payment-updated') {
                if ($case['kind'] !== 'payment_revision' || !($case['details']['ready'] ?? false) || !isset($c['uuid']) ||
                    ($c['proposal_status']['final'] ?? false)) { throw new \InvalidArgumentException('Revisão não está pronta'); }
                $c['state'] = 'submitted';
                $c['payment_review_completed_at'] = time();
                $this->store->schedule($key, 'status', time(), true);
            } elseif ($action === 'retry-task') {
                if (!in_array($case['kind'], ['simulation_exhausted','link_exhausted','status_exhausted'], true) || $c['state'] === 'finished') {
                    throw new \InvalidArgumentException('Não é uma consulta esgotada ativa');
                }
                $kind = str_replace('_exhausted', '', $case['kind']);
                if ($kind === 'simulation') {
                    if (isset($c['uuid'])) { throw new \InvalidArgumentException('Proposta já existente'); }
                    $c['state'] = 'simulation_retry';
                }
                $this->store->schedule($key, $kind, time(), true);
            } elseif ($action === 'resume') {
                if ($c['state'] !== 'human' || in_array($c['resume_state'] ?? '', ['review','submitting'], true)) {
                    throw new \InvalidArgumentException('Não é permitido retomar este estado sem reconciliação');
                }
                $c['state'] = $c['resume_state'] ?? 'start';
                unset($c['resume_state']);
            } else {
                throw new \InvalidArgumentException('Ação desconhecida');
            }
            $this->store->save($key, $c);
            $this->store->db->prepare('UPDATE human_cases SET status="resolved",resolution=?,updated=? WHERE id=?')
                ->execute([$evidence, time(), $id]);
            $this->event($id, $action);
        });
    }

    private function event(int $id, string $action): void
    {
        $this->store->db->prepare('INSERT INTO operator_events(case_id,action,created) VALUES(?,?,?)')->execute([$id,$action,time()]);
    }
}
