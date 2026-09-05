<?php
declare(strict_types=1);
namespace App\Clients;

use App\Config\Config;
use App\Services\Diagnostics;
use GuzzleHttp\ClientInterface;

final class JsonClient
{
    public function __construct(private ClientInterface $http, private Config $config) {}

    public function post(string $service, string $path, array $payload): array
    {
        if (!$this->config->enabled()) { throw new ApiException($service, 403); }
        $base = $this->config->required($service.'_BASE_URL');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https') { throw new ApiException($service, 400); }
        $header = $service === 'UY3' ? $this->config->get('UY3_AUTH_HEADER', 'Authorization') : 'Authorization';
        $secret = $this->config->required($service === 'UY3' ? 'UY3_AUTH_TOKEN' : 'CLIKCHAT_TOKEN');
        $token = $secret;
        if (strcasecmp($header, 'Authorization') === 0 &&
            ($service !== 'UY3' || $this->config->get('UY3_AUTH_BEARER', 'true') === 'true')) {
            $token = 'Bearer '.preg_replace('/^(Bearer\s+)+/i', '', trim($token));
        }
        try {
            $response = $this->http->request('POST', rtrim($base, '/').'/'.ltrim($path, '/'), [
                'headers' => [$header => $token, 'Accept' => 'application/json'],
                'json' => $payload,
                'timeout' => max(1, (int) $this->config->get('HTTP_TIMEOUT', '90')),
                'connect_timeout' => 10, 'http_errors' => false, 'allow_redirects' => false,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException) {
            throw new ApiException($service);
        }
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $retry = $response->getHeaderLine('Retry-After');
        $retryAfter = $retry === '' ? null : (ctype_digit($retry) ? (int) $retry : max(0, (int) strtotime($retry) - time()));
        if ($status < 200 || $status >= 300) {
            throw new ApiException($service, $status, is_array($body) ? Diagnostics::response($body, [$secret, $token]) : [], $retryAfter);
        }
        if (!is_array($body)) { throw new ApiException($service, $status, [], null, true); }
        return $body;
    }
}
