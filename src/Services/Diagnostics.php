<?php
declare(strict_types=1);
namespace App\Services;

final class Diagnostics
{
    public static function response(array $body, array $secrets = []): array
    {
        $allowed = ['code','message','mensagem','erro','erro_descricao','errors','status','statusDisplay','reason','motivo'];
        $data = array_intersect_key($body, array_flip($allowed));
        if (isset($body['error'])) { $data['error'] = $body['error']; }
        return self::clean($data, $secrets);
    }

    private static function clean(array $data, array $secrets, int $depth = 0): array
    {
        if ($depth > 4) { return []; }
        $result = [];
        foreach (array_slice($data, 0, 30, true) as $key => $value) {
            if (preg_match('/token|password|senha|authorization|secret|api.?key/i', (string) $key)) { continue; }
            if (is_array($value)) { $result[$key] = self::clean($value, $secrets, $depth + 1); }
            elseif (is_scalar($value)) {
                $text = (string) $value;
                foreach ($secrets as $secret) { if ($secret !== '') { $text = str_replace($secret, '[redacted]', $text); } }
                $text = preg_replace('/https?:\/\/\S+|Bearer\s+\S+|\b\d{3}[. ]?\d{3}[. ]?\d{3}[- ]?\d{2}\b/i', '[redacted]', $text) ?? '';
                $result[$key] = mb_substr($text, 0, 600);
            }
        }
        return $result;
    }
}
