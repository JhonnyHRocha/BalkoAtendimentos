<?php
declare(strict_types=1);
namespace App\Config;

final class Preflight
{
    public const REQUIRED = [
        'WEBHOOK_SECRET','CLIKCHAT_BASE_URL','CLIKCHAT_TOKEN',
        'UY3_BASE_URL','UY3_AUTH_TOKEN','UY3_ROBO_ID','UY3_USER_ID','UY3_TENANT',
    ];

    /** Relatório contém somente nomes de variáveis, nunca seus valores. */
    public static function inspect(Config $config): array
    {
        $missing = [];
        $invalid = [];
        foreach (self::REQUIRED as $key) {
            if (trim($config->get($key)) === '') { $missing[] = $key; }
        }
        foreach (['CLIKCHAT_COMPANY_ID','CLIKCHAT_CHANNEL_ID'] as $key) {
            $v = $config->get($key);
            if ($v !== '' && (!ctype_digit($v) || (int)$v <= 0)) { $invalid[] = $key; }
        }
        foreach (['CLIKCHAT_BASE_URL','UY3_BASE_URL'] as $key) {
            $v = $config->get($key);
            if ($v !== '' && !$config->validBaseUrl(str_replace('_BASE_URL', '', $key), $v)) { $invalid[]=$key; }
        }
        foreach (['EXTERNAL_CALLS_ENABLED','UY3_AUTH_BEARER','UY3_ALLOW_HTTP'] as $key) {
            if (!in_array($config->get($key), ['', 'false','true'], true)) { $invalid[]=$key; }
        }
        foreach (['HTTP_TIMEOUT','OUTBOX_MAX_ATTEMPTS','LINK_INTERVAL_SECONDS','LINK_MAX_ATTEMPTS',
            'STATUS_INTERVAL_SECONDS','STATUS_MAX_ATTEMPTS','SIMULATION_INTERVAL_SECONDS','SIMULATION_MAX_ATTEMPTS','UY3_MIN_VALUE'] as $key) {
            $v = $config->get($key);
            if ($v !== '' && (!is_numeric($v) || (float)$v<=0)) { $invalid[]=$key; }
        }
        $v=$config->get('DEBOUNCE_SECONDS');
        if ($v!=='' && (!ctype_digit($v))) { $invalid[]='DEBOUNCE_SECONDS'; }
        return ['missing'=>$missing,'invalid'=>$invalid];
    }
}
