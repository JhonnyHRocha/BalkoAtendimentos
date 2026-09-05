<?php
declare(strict_types=1);
$app = require dirname(__DIR__).'/src/bootstrap.php';
if (!$app['config']->enabled()) { fwrite(STDERR, "Configure EXTERNAL_CALLS_ENABLED=true para executar o worker.\n"); exit(1); }
foreach (['WEBHOOK_SECRET','CLIKCHAT_COMPANY_ID','CLIKCHAT_CHANNEL_ID','CLIKCHAT_BASE_URL','CLIKCHAT_TOKEN','UY3_BASE_URL','UY3_AUTH_TOKEN','UY3_ROBO_ID','UY3_USER_ID','UY3_TENANT'] as $key) { $app['config']->required($key); }
$worker = new App\Jobs\Worker($app['store'], $app['service'], $app['clik'], $app['config']);
do {
    $worked = $worker->once();
    if (in_array('--once', $argv, true)) { break; }
    if (!$worked) { sleep(1); }
} while (true);
