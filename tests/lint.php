<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$count = 0;
foreach (['src','bin','tests'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $code);
        if ($code !== 0) { echo implode("\n", $output)."\n"; exit(1); }
        $output = []; $count++;
    }
}
echo "Sintaxe válida: $count arquivos PHP.\n";
