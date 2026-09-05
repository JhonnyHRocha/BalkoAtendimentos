<?php
declare(strict_types=1);
namespace App\Clients;
use App\Config\Config;
final class ClikChatClient
{
    public function __construct(private JsonClient $http, private Config $config) {}
    public function send(array $message, string $text): void
    {
        $this->http->post('CLIKCHAT', '/api/send-message', [
            'number' => $message['number'], 'whatsappId' => $message['channel'],
            'origin' => $this->config->get('CLIKCHAT_ORIGIN', 'assistente_virtual'), 'body' => $text,
        ]);
    }
}
