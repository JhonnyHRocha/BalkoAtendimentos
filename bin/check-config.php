<?php
declare(strict_types=1);
// Não abre banco, não constrói clients e não faz chamadas de rede.
require dirname(__DIR__).'/vendor/autoload.php';
try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
    $config = App\Config\Config::environment();
    $report = App\Config\Preflight::inspect($config);
    $extensions = array_values(array_filter(['pdo_sqlite','iconv','mbstring'], static fn($name)=>!extension_loaded($name)));
    echo json_encode([
        'env_loaded'=>true,
        'external_calls_enabled'=>$config->enabled(),
        'missing'=>$report['missing'],
        'invalid'=>$report['invalid'],
        'missing_extensions'=>$extensions,
    ], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit($report['missing'] || $report['invalid'] || $extensions ? 2 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Não foi possível carregar a configuração local. Nenhum valor foi exibido.\n");
    exit(1);
}
