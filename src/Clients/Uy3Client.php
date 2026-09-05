<?php
declare(strict_types=1);
namespace App\Clients;

use App\Config\Config;
use App\Services\ProposalStatus;

final class Uy3Client
{
    public function __construct(private JsonClient $http, private Config $config) {}
    public function simulate(string $cpf, ?float $value = null): array
    {
        $payload = ['id_robo'=>$this->config->required('UY3_ROBO_ID'), 'cpf'=>$cpf];
        if ($value !== null) { $payload['valor_liquido'] = $value; }
        return $this->http->post('UY3', '/uy3/clt/simulacao-completa', $payload);
    }
    public function register(array $payload): array
    {
        return $this->http->post('UY3', '/uy3/clt/cadastrar-proposta', $payload);
    }
    public function robot(): string
    {
        return $this->config->get('UY3_DIGITACAO_ROBO_ID') ?: $this->config->required('UY3_ROBO_ID');
    }
    public function link(string $uuid): ?string
    {
        $r = $this->http->post('UY3', '/uy3/link_assinatura', ['id_robo'=>$this->robot(), 'id_proposta'=>$uuid]);
        $url = ($r[0] ?? $r)['url'] ?? null;
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME)==='https' ? $url : null;
    }
    public function status(string $uuid): ?string
    {
        return $this->statusDetails($uuid)['label'] ?? null;
    }
    public function statusDetails(string $uuid): ?array
    {
        $r = $this->http->post('UY3', '/uy3/status_proposta', ['id_robo'=>$this->robot(), 'uuid_proposta'=>$uuid]);
        $r = $r['proposta'] ?? $r;
        if (!is_array($r)) { throw new ApiException('UY3', 200, [], null, true); }
        $items = array_is_list($r) ? $r : [$r];
        $anonymous = null;
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $id = $item['id_proposta'] ?? $item['creditNoteId'] ?? $item['id'] ?? null;
            if (!is_string($item['status'] ?? null) || trim($item['status']) === '') { continue; }
            if ($id !== null && strcasecmp((string)$id, $uuid) === 0) { return ProposalStatus::normalize($item); }
            if ($id === null && count($items) === 1) { $anonymous = ProposalStatus::normalize($item); }
        }
        return $anonymous;
    }
}
