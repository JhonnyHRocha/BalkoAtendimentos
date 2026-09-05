<?php
declare(strict_types=1);
namespace App\Services;

final class Offer
{
    public const TERMS = [12,18,24,30,36];

    public static function normalize(array $r, ?int $term = null): ?array
    {
        return self::evaluate($r, $term)['offer'] ?? null;
    }

    public static function evaluate(array $r, ?int $term = null): array
    {
        $margin = $r['margem'][0] ?? [];
        $result = $margin['result'][0] ?? [];
        if ((int) ($result['tipoBloqueio']['codigo'] ?? 0) !== 0) {
            return ['kind'=>'blocked', 'details'=>Diagnostics::response(['code'=>$result['tipoBloqueio']['codigo'],
                'message'=>$result['tipoBloqueio']['descricao'] ?? 'Bloqueio informado pela UY3'])];
        }
        if (($result['elegivel'] ?? true) === false) {
            return ['kind'=>'ineligible','details'=>Diagnostics::response(['message'=>$result['motivoInelegibilidade'] ?? 'Não elegível'])];
        }
        if (($margin !== [] && ($margin['status'] ?? '') !== 'OK') ||
            !empty($r['error']) || !empty($r['erro']) || !empty($r['erro_descricao']) || !empty($r['simulacao']['error'])) {
            $details = Diagnostics::response($r + ['status'=>$margin['status'] ?? '']);
            if (!empty($r['simulacao']['error'])) { $details['error'] = Diagnostics::response(['error'=>$r['simulacao']['error']]); }
            $description = Text::normalize(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $kind = preg_match('/nao elegivel|inelegivel|sem margem|margem insuficiente|nao possui margem/', $description) ? 'ineligible' :
                (str_contains($description, 'bloque') ? 'blocked' : 'unavailable');
            return ['kind'=>$kind,'details'=>$details];
        }
        $a = $r['simulacao']['amortization'] ?? [];
        $payment = self::payment($r);
        $offer = ['value'=>$a['liquidValue'] ?? null,'payment'=>$payment,'term'=>$a['numberOfPayments'] ?? null,
            'first_payment'=>$a['firstPaymentDate'] ?? null,'product'=>$a['productName'] ?? null];
        if ($term !== null) {
            $tables = array_values(array_filter($r['tabelas'] ?? [], static fn($t) =>
                is_array($t) && in_array((int) ($t['parcelas'] ?? 0), self::TERMS, true)));
            usort($tables, static fn($a, $b) => abs((int)$a['parcelas']-$term) <=> abs((int)$b['parcelas']-$term));
            if ($tables !== []) {
                $table = $tables[0];
                $tablePayment = $table['valorParcela'] ?? null;
                $gross = $a['requestedAmount'] ?? null;
                $suspicious = !is_numeric($tablePayment) || $tablePayment <= 0 ||
                    (is_numeric($gross) && abs((float)$tablePayment-(float)$gross)<0.01);
                // Uma parcela da oferta primária não comprova o valor em outro prazo.
                if ($suspicious) { $tablePayment = (int)$table['parcelas'] === (int)($a['numberOfPayments'] ?? 0) ? $payment : null; }
                $offer = ['value'=>$table['valorLiberado'] ?? null,'payment'=>$tablePayment,'term'=>$table['parcelas'],
                    'first_payment'=>$table['primeiroDesconto'] ?? $offer['first_payment'],
                    'product'=>$table['productName'] ?? $offer['product']];
            }
        }
        if (!is_numeric($offer['value']) || $offer['value'] <= 0 ||
            !is_numeric($offer['payment']) || $offer['payment'] <= 0 ||
            !in_array((int)$offer['term'], self::TERMS, true)) {
            return ['kind'=>'unavailable','details'=>['code'=>'invalid_offer']];
        }
        $offer['value'] = round((float)$offer['value'], 2);
        $offer['payment'] = round((float)$offer['payment'], 2);
        $offer['term'] = (int)$offer['term'];
        return ['kind'=>'available','offer'=>$offer,'details'=>[]];
    }

    private static function payment(array $r): ?float
    {
        $value = $r['simulacao']['warranty']['totalValue'] ?? null;
        if (is_numeric($value) && $value > 0) { return (float)$value; }
        foreach ([$r['simulacao']['amortization']['paymentScheduleItems'] ?? [],
            $r['simulacao_raw']['amortization']['paymentScheduleItems'] ?? []] as $source=>$items) {
            foreach ($items as $item) {
                if (is_numeric($item['payment'] ?? null) && $item['payment'] > 0) {
                    return (float)$item['payment'] / ($source === 1 ? 100 : 1);
                }
            }
        }
        return null;
    }
}
