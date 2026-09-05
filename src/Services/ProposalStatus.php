<?php
declare(strict_types=1);
namespace App\Services;

final class ProposalStatus
{
    public static function normalize(array $item): array
    {
        $code = trim((string)($item['status'] ?? ''));
        $label = trim((string)($item['statusDisplay'] ?? $item['status_display'] ?? '')) ?: $code;
        $keys = [Text::normalize($code), Text::normalize($label)];
        $paid = (bool) array_intersect($keys, ['liquidation','liquidacao','liquidado','liquidated','finished','encerrado']);
        $revision = (bool) array_intersect($keys, ['paymentrevision','payment revision','revisao de pagamento','pagamento recusado','payment rejected']);
        $final = $paid || (bool) array_intersect($keys, [
            'canceled','cancelled','cancelado','disapproved','reprovado','recusado','rejected',
            'expired','expirado','error','erro',
        ]);
        $details = Diagnostics::response($item);
        foreach (array_reverse(is_array($item['timeline'] ?? null) ? $item['timeline'] : []) as $entry) {
            if (!is_array($entry)) { continue; }
            $normalized = Text::normalize(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (str_contains($normalized, 'paymentrevision') || str_contains($normalized, 'revisao de pagamento')) {
                $details['payment_revision'] = Diagnostics::response($entry);
                break;
            }
        }
        return ['code'=>$code,'label'=>$label,'paid'=>$paid,'final'=>$final && !$revision,'revision'=>$revision,'details'=>$details];
    }
}
