<?php
declare(strict_types=1);
namespace App\Models;

use App\Config\Config;

final class Message
{
    public static function parse(array $input, Config $config): ?array
    {
        if (array_is_list($input) && count($input) !== 1) { throw new \InvalidArgumentException('Envelope inválido'); }
        $item = array_is_list($input) ? $input[0] : $input;
        if (!is_array($item)) { throw new \InvalidArgumentException('Envelope inválido'); }
        $body = $item['body'] ?? $item;
        if (!is_array($body)) { throw new \InvalidArgumentException('Body inválido'); }
        $m = $body['mensagem'] ?? [];
        $t = $body['ticket'] ?? [];
        if (!is_array($t) || (!is_string($m) && !is_array($m))) { throw new \InvalidArgumentException('Mensagem inválida'); }
        $text = is_string($m) ? $m : ($m['body'] ?? '');
        $m = is_array($m) ? $m : [];
        if (filter_var($m['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN) || filter_var($t['isGroup'] ?? false, FILTER_VALIDATE_BOOLEAN)) { return null; }
        $contact = $t['contact'] ?? [];
        if (!is_array($contact)) { throw new \InvalidArgumentException('Contato inválido'); }
        $company = $body['empresa_id'] ?? $t['companyId'] ?? 0;
        $channel = $body['canal_id'] ?? $m['whatsappId'] ?? 0;
        $number = $body['numero_cliente'] ?? $contact['number'] ?? '';
        $id = $m['wid'] ?? $m['id'] ?? $body['message_id'] ?? '';
        $ticket = $m['ticketId'] ?? $t['id'] ?? '';
        foreach ([$company,$channel,$number,$id,$ticket] as $value) {
            if (!is_string($value) && !is_int($value)) { throw new \InvalidArgumentException('Identificador inválido'); }
        }
        $company = (int)$company;
        $channel = (int)$channel;
        if ($company !== (int)$config->required('CLIKCHAT_COMPANY_ID') || $channel !== (int)$config->required('CLIKCHAT_CHANNEL_ID')) { return null; }
        $number = preg_replace('/\D/', '', (string)$number);
        if (!is_string($text) || !preg_match('/^\d{10,15}$/', $number) || (string)$id === '' ||
            strlen((string)$id) > 255 || strlen($text) > 4000 || strlen((string)$ticket)>100) {
            throw new \InvalidArgumentException('Identificação ou texto inválido');
        }
        $media = $m['mediaType'] ?? 'conversation';
        if (!is_string($media)) { throw new \InvalidArgumentException('Tipo de mídia inválido'); }
        $url = $m['mediaUrl'] ?? $m['mediaPath'] ?? $m['url'] ?? null;
        if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL) ||
            parse_url($url,PHP_URL_SCHEME)!=='https') { $url=null; }
        return ['key'=>hash('sha256',"$company:$channel:$number:$ticket"),'id'=>(string)$id,
            'number'=>$number,'channel'=>$channel,'ticket'=>(string)$ticket,'text'=>trim($text),
            'media'=>$media,'media_url'=>$url];
    }
}
