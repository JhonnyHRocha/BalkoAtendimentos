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
    public function validBaseUrl(string $service, string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) { return false; }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) { return false; }
        foreach (['user','pass','query','fragment'] as $part) {
            if (array_key_exists($part, $parts)) { return false; }
        }
        return ($parts['scheme'] ?? '') === 'https' ||
            ($service === 'UY3' && $this->get('UY3_ALLOW_HTTP') === 'true' && ($parts['scheme'] ?? '') === 'http');
    }
    public function enabled(): bool { return $this->get('EXTERNAL_CALLS_ENABLED') === 'true'; }
}
