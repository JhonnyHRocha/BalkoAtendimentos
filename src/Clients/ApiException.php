<?php
declare(strict_types=1);
namespace App\Clients;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $service,
        public readonly ?int $status = null,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
        public readonly bool $protocolError = false,
    ) {
        parent::__construct('Falha na integração '.$service);
    }

    public function temporary(): bool
    {
        return $this->status === null || in_array($this->status, [408, 425, 429], true)
            || $this->status >= 500 || $this->protocolError;
    }

    public function rejected(): bool
    {
        return in_array($this->status, [400, 422], true) && !$this->protocolError;
    }

    public function diagnostic(): array
    {
        return ['service' => $this->service, 'http_status' => $this->status,
            'temporary' => $this->temporary(), 'details' => $this->details];
    }
}
