<?php
declare(strict_types=1);
$app = require dirname(__DIR__).'/src/bootstrap.php';
$ops = new App\Services\Operations($app['store']);
$command = $argv[1] ?? 'list';
$id = (int)($argv[2] ?? 0);
$lock = fopen($app['store']->path.'.worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Worker ocupado. Tente novamente ou pare o worker durante a operação.\n");
    exit(1);
}
try {
    $result = match ($command) {
        'list' => $ops->pending(),
        'show' => $ops->show($id),
        'reply' => $ops->reply($id, $argv[3] ?? ''),
        'resolve' => $ops->resolve($id, $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? null),
        default => throw new InvalidArgumentException('Use list, show ID, reply ID TEXTO ou resolve ID ACAO EVIDENCIA [UUID]'),
    };
    echo json_encode($result ?? ['ok'=>true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
