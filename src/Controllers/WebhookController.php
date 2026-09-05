<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Config\Config;
use App\Models\Message;
use App\Models\Store;
final class WebhookController
{
    public function __construct(private Store $store, private Config $config) {}
    public function handle(string $raw, string $secret): array
    {
        if (!hash_equals($this->config->required('WEBHOOK_SECRET'), $secret)) { return [401, ['error'=>'unauthorized']]; }
        if (strlen($raw) > 65536) { return [413, ['error'=>'payload_too_large']]; }
        try {
            $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($input)) { throw new \InvalidArgumentException('JSON inválido'); }
            $m = Message::parse($input, $this->config);
        } catch (\JsonException|\InvalidArgumentException|\TypeError) { return [422, ['error'=>'invalid_payload']]; }
        if ($m === null) { return [200, ['ignored'=>true]]; }
        return [200, ['ok'=>true, 'queued'=>$this->store->enqueue($m)]];
    }
}
