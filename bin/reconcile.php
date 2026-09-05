<?php
declare(strict_types=1);
$app = require dirname(__DIR__).'/src/bootstrap.php';
$key = $argv[1] ?? '';
$uuid = $argv[2] ?? '';
$evidence = $argv[3] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $key) || $uuid === '' || trim($evidence) === '') {
    fwrite(STDERR, "Uso: php bin/reconcile.php HASH UUID_CONFIRMADO|--not-created EVIDENCIA_DA_CONFERENCIA\n");
    exit(1);
}
$lock = fopen($app['store']->path.'.worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Worker ocupado. Tente novamente.'); }
try {
    $s = $app['store']->db->prepare('SELECT id FROM human_cases WHERE conversation=? AND kind="registration_uncertain" AND status="open"');
    $s->execute([$key]);
    $id = $s->fetchColumn();
    if (!$id) { throw new RuntimeException('Pendência de cadastro incerto não encontrada'); }
    (new App\Services\Operations($app['store']))->resolve((int)$id, $uuid === '--not-created' ? 'not-created' : 'associate', $evidence, $uuid === '--not-created' ? null : $uuid);
    echo "Conferência registrada. A proposta existente será acompanhada, ou um novo aceite final será solicitado.\n";
} finally { flock($lock,LOCK_UN); fclose($lock); }
