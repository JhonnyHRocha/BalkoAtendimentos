<?php
declare(strict_types=1);
namespace App\Services;

use App\Clients\ApiException;
use App\Clients\Uy3Client;
use App\Config\Config;
use App\Models\Store;

final class AtendimentoService
{
    public function __construct(
        private Uy3Client $uy3,
        private ProposalPayload $builder,
        private Store $store,
        private Config $config = new Config([]),
    ) {}

    public function process(array $m, array &$c, ?int $now = null): string
    {
        $now ??= time();
        $c['route'] = array_intersect_key($m, array_flip(['key','number','company','channel']));
        $text = trim($m['text']);
        $command = Text::normalize($text);
        if ($c['state'] === 'submitting') { $this->uncertain($m['key'], $c, ['code'=>'interrupted_registration']); }
        if (Text::human($text)) { return $this->human($m['key'], $c, 'requested', ['reason'=>'customer_request']); }
        if (preg_match('/audio|ptt|image|video|document/i', $m['media'])) {
            return $this->human($m['key'], $c, 'media', ['media_type'=>$m['media'],'media_url'=>$m['media_url'] ?? null,'ticket'=>$m['ticket'] ?? null]);
        }
        if ($c['state'] === 'review') { return 'A pendência de cadastro foi registrada para conferência. Não faremos outro cadastro até o operador verificar a proposta.'; }
        if ($c['state'] === 'human') { return 'Sua mensagem está registrada na fila do atendente. Aguarde o retorno da equipe.'; }
        // Qualquer UUID existente impede a entrada no caminho de cadastro.
        if (isset($c['uuid'])) { return $this->existing($m, $c, $now); }
        if (Text::no($text)) {
            $this->store->stopTasks($m['key']);
            $c = ['state'=>'closed','data'=>[],'route'=>$c['route']];
            return 'Atendimento encerrado sem cadastrar proposta. Para começar novamente, envie INICIAR.';
        }
        if ($text === '') { return 'Envie sua resposta por texto, por favor.'; }
        if ($c['state'] === 'closed') {
            if ($command !== 'iniciar') { return 'Para começar um novo atendimento, envie INICIAR.'; }
            $c = ['state'=>'start','data'=>[],'route'=>$c['route']];
        }
        if ($c['state'] === 'start') {
            $c['state'] = 'consent';
            return 'Olá! Para consultar uma oferta de crédito CLT na UY3, precisamos consultar seu CPF. Você autoriza? Responda SIM ou NÃO. Pode solicitar ATENDENTE a qualquer momento.';
        }
        if ($c['state'] === 'consent') {
            if (!Text::yes($text)) { return 'Você autoriza a consulta? Responda SIM ou NÃO.'; }
            $c['state'] = 'cpf';
            return 'Informe seu CPF com 11 dígitos.';
        }
        if ($c['state'] === 'cpf') {
            $cpf = Fields::cpf($text);
            if ($cpf === null) { return 'CPF inválido. Confira os 11 dígitos e envie novamente.'; }
            $c['data']['cpf'] = $cpf;
            $c['state'] = 'simulation';
            return $this->simulate($c);
        }
        if (in_array($c['state'], ['accept','simulation_failed','ineligible','blocked','simulation_retry'], true)) {
            $adjusted = $this->adjust($text, $c);
            if ($adjusted !== null) {
                if ($adjusted !== '') { return $adjusted; }
                $this->store->db->prepare('UPDATE tasks SET done=1 WHERE conversation=? AND kind="simulation"')->execute([$m['key']]);
                return $this->simulate($c);
            }
        }
        if (in_array($c['state'], ['simulation','simulation_failed','ineligible','blocked'], true)) {
            if (in_array($command, ['tentar','simular','consultar novamente'], true)) { return $this->simulate($c); }
            return 'Envie TENTAR para uma nova consulta, VALOR seguido do valor desejado, PRAZO seguido das parcelas ou ATENDENTE.';
        }
        if ($c['state'] === 'simulation_retry') { return 'A consulta será repetida automaticamente. Avisaremos assim que houver retorno. Você também pode solicitar ATENDENTE.'; }
        if ($c['state'] === 'accept') {
            if (!Text::yes($text)) { return 'Responda SIM para continuar, NÃO para recusar, VALOR 2000 para ajustar o valor ou PRAZO 12 para ajustar o prazo.'; }
            $c['state'] = 'collect';
            return Fields::QUESTIONS[Fields::next($c['data'])];
        }
        if ($c['state'] === 'collect') { return $this->collect($text, $c); }
        if ($c['state'] === 'registration_rejected') {
            if ($command === 'corrigir') { return $this->resetData($c); }
            return 'O cadastro foi rejeitado pela UY3. Envie CORRIGIR para revisar seus dados ou ATENDENTE para a equipe verificar a pendência.';
        }
        if ($c['state'] === 'confirm') {
            if ($command === 'corrigir') { return $this->resetData($c); }
            if (!in_array($command, ['confirmar','confirmo','sim confirmo'], true)) {
                return 'Envie CONFIRMAR para cadastrar, CORRIGIR para refazer os dados ou CANCELAR.';
            }
            return $this->register($m['key'], $c, $now);
        }
        throw new \RuntimeException('Estado inválido');
    }

    private function resetData(array &$c): string
    {
        $c['data'] = ['cpf'=>$c['data']['cpf']];
        $c['state'] = 'collect';
        return Fields::QUESTIONS['nome'];
    }

    private function collect(string $text, array &$c, bool $revision = false): string
    {
        $invalid = Fields::collect($text, $c['data'], $revision);
        if ($invalid !== null) { return 'Dado inválido. '.Fields::QUESTIONS[$invalid]; }
        $next = Fields::next($c['data'], $revision);
        if ($next !== null) { return Fields::QUESTIONS[$next]; }
        $c['state'] = $revision ? 'payment_confirm' : 'confirm';
        return $revision
            ? 'Confirma os novos dados de pagamento para revisão da proposta existente? Envie CONFIRMAR ou CORRIGIR. Não será criada outra proposta.'
            : 'Dados coletados. Confirma que os dados e a conta são seus e autoriza cadastrar a oferta aceita? Responda CONFIRMAR, CORRIGIR ou CANCELAR.';
    }

    private function existing(array $m, array &$c, int $now): string
    {
        $command = Text::normalize($m['text']);
        if (Text::no($m['text'])) {
            return $this->human($m['key'], $c, 'cancellation', ['uuid'=>$c['uuid'],'reason'=>'cancellation_requested']);
        }
        if ($c['state'] === 'finished') {
            return 'A proposta está finalizada: '.($c['proposal_status']['label'] ?? 'Finalizada').'. Para outro atendimento, solicite ATENDENTE.';
        }
        if ($command === 'status') {
            $this->store->schedule($m['key'], 'status', $now, true);
            return isset($c['proposal_status']) ? 'Último status: '.$c['proposal_status']['label'].'. Estamos atualizando a consulta.'
                : 'Consulta de status agendada. Avisaremos assim que houver retorno.';
        }
        if ($command === 'link') {
            if (!empty($c['link'])) { return 'Link de assinatura: '.$c['link']; }
            $this->store->schedule($m['key'], 'link', $now, true);
            return 'Estamos buscando o link. Ele será enviado automaticamente quando estiver disponível.';
        }
        if ($c['state'] === 'payment_collect') { return $this->collect($m['text'], $c, true); }
        if ($c['state'] === 'payment_confirm') {
            if ($command === 'corrigir') {
                foreach (Fields::PAYMENT_FIELDS as $key) { unset($c['data'][$key]); }
                $c['state'] = 'payment_collect';
                return Fields::QUESTIONS['pagamento'];
            }
            if (!in_array($command, ['confirmar','confirmo','sim confirmo'], true)) { return 'Envie CONFIRMAR ou CORRIGIR para a revisão do pagamento.'; }
            $c['state'] = 'payment_review';
            $c['payment_revision_ready_at'] = $now;
            $case = $this->store->case($m['key'], 'payment_revision', [
                'uuid'=>$c['uuid'],'ready'=>true,'payment'=>array_intersect_key($c['data'], array_flip(Fields::PAYMENT_FIELDS)),
                'status'=>$c['proposal_status'] ?? [],
            ]);
            return 'Os novos dados foram enviados à fila da equipe para corrigir o pagamento da proposta existente. Pendência '.$case.'. Não foi criada outra proposta.';
        }
        if ($c['state'] === 'payment_review') { return 'A equipe recebeu seus dados para revisão do pagamento. O acompanhamento da proposta continua.'; }
        return !empty($c['link']) ? 'Link de assinatura: '.$c['link'].'. Acompanharemos o status automaticamente.'
            : 'Sua proposta permanece cadastrada. O link e as atualizações serão enviados automaticamente.';
    }

    /** Retorna null quando não é ajuste, vazio quando deve simular e texto quando inválido. */
    private function adjust(string $text, array &$c): ?string
    {
        $normalized = Text::normalize($text);
        if ($normalized === 'margem' || $normalized === 'valor maximo') {
            unset($c['requested_value']);
        } elseif (preg_match('/^(?:valor|quero)\s+(?:R\$\s*)?([\d.,]+)(?:\s+em\s+(\d+)\s*(?:vezes|parcelas|meses)?)?\s*$/iu', trim($text), $m)) {
            $value = Text::money($m[1]);
            if ($value === null || $value < (float)$this->config->get('UY3_MIN_VALUE','1000')) {
                return 'Informe um valor a partir de R$ '.$this->config->get('UY3_MIN_VALUE','1000').', ou MARGEM para consultar o disponível.';
            }
            if (isset($m[2]) && !in_array((int)$m[2], Offer::TERMS, true)) { return 'Prazos disponíveis: 12, 18, 24, 30 ou 36 parcelas.'; }
            $c['requested_value'] = $value;
            if (isset($m[2])) { $c['requested_term'] = (int)$m[2]; }
        } elseif (preg_match('/^(?:prazo|parcelas)\s+(\d+)(?:\s+(?:meses|parcelas|vezes))?$/', $normalized, $m)) {
            if (!in_array((int)$m[1], Offer::TERMS, true)) { return 'Prazos disponíveis: 12, 18, 24, 30 ou 36 parcelas.'; }
            $c['requested_term'] = (int)$m[1];
        } else { return null; }
        unset($c['offer']);
        $c['state'] = 'simulation';
        return '';
    }

    public function simulate(array &$c): string
    {
        $c['state'] = 'simulation';
        $outcome = Offer::evaluate($this->uy3->simulate($c['data']['cpf'], $c['requested_value'] ?? null), $c['requested_term'] ?? null);
        $c['simulation_result'] = $outcome;
        if ($outcome['kind'] === 'unavailable') { throw new ApiException('UY3', 503, $outcome['details']); }
        if ($outcome['kind'] !== 'available') {
            unset($c['offer']);
            $c['state'] = $outcome['kind'];
            return $outcome['kind'] === 'blocked'
                ? 'A UY3 informou um bloqueio para esta consulta. Não há oferta para aceitar. Envie ATENDENTE para verificar ou CANCELAR.'
                : 'A UY3 não encontrou elegibilidade ou margem disponível nesta consulta. Envie ATENDENTE para orientação ou CANCELAR.';
        }
        $c['offer'] = $outcome['offer'];
        $c['state'] = 'accept';
        $offer = $c['offer'];
        $text = 'Oferta UY3: R$ '.number_format($offer['value'],2,',','.').' em '.$offer['term'].' parcelas de R$ '.number_format($offer['payment'],2,',','.').'.';
        if (!empty($offer['first_payment'])) { $text .= ' Primeiro desconto: '.substr((string)$offer['first_payment'],0,10).'.'; }
        if (!empty($offer['product'])) { $text .= ' Tabela: '.$offer['product'].'.'; }
        if (isset($c['requested_term']) && $c['requested_term'] !== $offer['term']) { $text .= ' O prazo solicitado não estava disponível; esta é a alternativa retornada.'; }
        return $text.' Deseja continuar? Responda SIM ou NÃO. Para ajustar, envie VALOR seguido do valor ou PRAZO seguido das parcelas.';
    }

    private function register(string $key, array &$c, int $now): string
    {
        $payload = $this->builder->build($c['data'], $c['offer']);
        $c['state'] = 'submitting';
        $c['registration_started_at'] = $now;
        $this->store->save($key, $c);
        try { $result = $this->uy3->register($payload); }
        catch (ApiException $e) {
            if ($e->rejected()) { return $this->rejected($key, $c, $e->diagnostic()); }
            $this->uncertain($key, $c, $e->diagnostic());
            return 'Não recebemos confirmação segura do cadastro. Uma pendência foi aberta para a equipe conferir, sem repetir a proposta.';
        } catch (\Throwable) {
            $this->uncertain($key, $c, ['code'=>'unexpected_registration_failure']);
            return 'O cadastro precisa de conferência. A equipe recebeu a pendência e não haverá nova tentativa automática.';
        }
        if (!is_string($result['id_proposta'] ?? null) || trim($result['id_proposta']) === '') {
            $details = Diagnostics::response($result);
            if (!empty($result['errors']) || !empty($result['erro']) || !empty($result['erro_descricao'])) {
                return $this->rejected($key, $c, $details);
            }
            $this->uncertain($key, $c, $details + ['code'=>'missing_proposal_id']);
            return 'O cadastro não retornou confirmação segura. A equipe foi acionada para conferir antes de qualquer nova tentativa.';
        }
        $c['uuid'] = $result['id_proposta'];
        $c['proposal_number'] = $result['creditNoteNo'] ?? $result['credit_note_no'] ?? null;
        $c['state'] = 'submitted';
        $c['registered_at'] = $now;
        $this->store->transaction(function () use ($key, $c, $now) {
            $this->store->save($key, $c);
            $this->store->schedule($key, 'link', $now, true);
            $this->store->schedule($key, 'status', $now + $this->interval('status'), true);
            $this->store->resolveKind($key, 'registration_rejected', 'Cadastro confirmado');
        });
        return 'Proposta cadastrada. Buscaremos o link de assinatura e acompanharemos o status automaticamente.';
    }

    private function rejected(string $key, array &$c, array $details): string
    {
        $c['state'] = 'registration_rejected';
        $c['registration_error'] = $details;
        $this->store->transaction(function () use ($key, $c, $details) {
            $this->store->save($key, $c);
            $this->store->case($key, 'registration_rejected', $details);
        });
        return 'A UY3 rejeitou o cadastro. A pendência está registrada para a equipe. Envie CORRIGIR para revisar os dados ou ATENDENTE.';
    }

    public function uncertain(string $key, array &$c, array $details): void
    {
        $c['state'] = 'review';
        $c['registration_error'] = $details;
        $this->store->transaction(function () use ($key, $c, $details) {
            $this->store->save($key, $c);
            $this->store->case($key, 'registration_uncertain', $details);
        });
    }

    public function human(string $key, array &$c, string $kind, array $details): string
    {
        if ($c['state'] !== 'human') { $c['resume_state'] = $c['state']; }
        $c['state'] = 'human';
        $case = $this->store->case($key, $kind, $details);
        return 'Seu atendimento foi registrado na fila da equipe. Protocolo '.$case.'. Um atendente poderá consultar as mensagens e responder por este canal.';
    }

    public function interval(string $kind): int
    {
        return max(1, (int)$this->config->get(strtoupper($kind).'_INTERVAL_SECONDS', $kind === 'status' ? '600' : '30'));
    }

    /** Executa somente consultas seguras; nunca chama cadastrar-proposta. */
    public function task(string $kind, array &$c, int $now): array
    {
        if ($kind === 'simulation') {
            if (!in_array($c['state'], ['simulation','simulation_retry'], true) || isset($c['uuid'])) { return ['again'=>false,'text'=>null]; }
            return ['again'=>false,'text'=>$this->simulate($c)];
        }
        if (!isset($c['uuid']) || $c['state'] === 'finished') { return ['again'=>false,'text'=>null]; }
        if ($kind === 'link') {
            if (!empty($c['link'])) { return ['again'=>false,'text'=>null]; }
            $link = $this->uy3->link($c['uuid']);
            if ($link === null) { return ['again'=>true,'text'=>null]; }
            $c['link'] = $link;
            $c['link_at'] = $now;
            return ['again'=>false,'text'=>'Acesse o link de assinatura: '.$link.'. Acompanharemos o andamento automaticamente.'];
        }
        $status = $this->uy3->statusDetails($c['uuid']);
        if ($status === null) { return ['again'=>true,'text'=>null]; }
        $previous = $c['proposal_status']['code'] ?? null;
        $c['proposal_status'] = $status;
        $c['status_at'] = $now;
        $changed = $previous !== $status['code'];
        if ($status['final']) {
            $c['state'] = 'finished';
            $c['finished_at'] = $now;
            return ['again'=>false,'text'=>$status['paid'] ? 'A UY3 confirmou o pagamento da proposta. Atendimento finalizado.'
                : 'A proposta foi finalizada com status: '.$status['label'].'. Para orientação, solicite ATENDENTE.'];
        }
        if ($status['revision'] && $changed) {
            foreach (Fields::PAYMENT_FIELDS as $key) { unset($c['data'][$key]); }
            $c['state'] = 'payment_collect';
            return ['again'=>true,'text'=>'A UY3 informou revisão do pagamento. Vamos coletar outra conta ou chave PIX para a equipe corrigir a proposta existente. '.Fields::QUESTIONS['pagamento']];
        }
        return ['again'=>true,'text'=>$changed ? 'Status da proposta: '.$status['label'].'. Seguimos acompanhando.' : null];
    }
}
