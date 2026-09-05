<?php
declare(strict_types=1);
namespace App\Services;
use App\Config\Config;
final class ProposalPayload
{
    public function __construct(private Config $config) {}
    public function build(array $data, array $offer): array
    {
        if (!Fields::cpf($data['cpf'] ?? '') || Fields::next($data) !== null || !in_array($offer['term'] ?? 0, Offer::TERMS, true)) {
            throw new \InvalidArgumentException('Cadastro incompleto');
        }
        $address = [];
        foreach (['logradouro','numero','bairro','cidade','uf','cep'] as $key) { $address[$key] = $data[$key]; }
        $bank = $data['pagamento'] === 'PIX'
            ? ['tipo_chave_pix' => $data['tipo_pix'], 'chave_pix' => $data['chave_pix']]
            : array_intersect_key($data, array_flip(['banco','agencia','conta','conta_digito']));
        $payload = [
            'id_robo' => $this->config->get('UY3_DIGITACAO_ROBO_ID') ?: $this->config->required('UY3_ROBO_ID'),
            'user_id' => $this->config->required('UY3_USER_ID'), 'tenant' => $this->config->required('UY3_TENANT'),
            'cpf' => $data['cpf'], 'nome' => $data['nome'], 'telefone' => $data['telefone'],
            'data_nascimento' => $data['data_nascimento'], 'endereco' => $address, 'conta_bancaria' => $bank,
            'parcelas' => $offer['term'],
        ];
        if ($offer['value'] >= (float) $this->config->get('UY3_MIN_VALUE', '1000')) { $payload['valor_liquido'] = $offer['value']; }
        return $payload;
    }
}
