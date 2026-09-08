<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '') !== 'test') {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}
$config = App\Config\Config::environment();
$path = $config->get('APP_DB_PATH', dirname(__DIR__).'/var/atendimento.sqlite');
$store = new App\Models\Store($path);
$http = new App\Clients\JsonClient(new GuzzleHttp\Client(), $config);
$uy3 = new App\Clients\Uy3Client($http, $config);
$clik = new App\Clients\ClikChatClient($http, $config);
$service = new App\Services\AtendimentoService($uy3, new App\Services\ProposalPayload($config), $store, $config);
return compact('config','store','uy3','clik','service');
