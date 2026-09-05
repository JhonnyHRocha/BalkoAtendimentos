<?php
declare(strict_types=1);
namespace App\Config;

final class Config
{
    public function __construct(private array $values) {}
    public static function environment(): self { return new self($_ENV + $_SERVER); }
    public function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';
        return $value === '' ? $default : (string) $value;
    }
    public function required(string $key): string
    {
        return $this->get($key) ?: throw new \RuntimeException("Configuração ausente: $key");
    }
    public function enabled(): bool { return $this->get('EXTERNAL_CALLS_ENABLED') === 'true'; }
}
