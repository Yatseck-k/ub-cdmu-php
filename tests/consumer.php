<?php
declare(strict_types=1);

$package = realpath(dirname(__DIR__));
$consumer = sys_get_temp_dir() . '/ub-cdmu-consumer-' . bin2hex(random_bytes(6));
mkdir($consumer . '/src', 0777, true);
copy($package . '/fixtures/plain-php/Example.php', $consumer . '/src/Example.php');
copy($package . '/fixtures/laravel/OrderService.php', $consumer . '/src/Laravel.php');
copy($package . '/fixtures/symfony/OrderService.php', $consumer . '/src/Symfony.php');
file_put_contents($consumer . '/composer.json', json_encode([
    'repositories' => [['type' => 'path', 'url' => $package, 'options' => ['symlink' => false]]],
    'require-dev' => ['yatseck-k/ub-cdmu-php' => '@dev'],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

try {
    foreach ([
        ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'],
        [$consumer . '/vendor/bin/ub-php', 'doctor', '--format', 'json'],
        [$consumer . '/vendor/bin/ub-php', 'check', '--format', 'json'],
        [$consumer . '/vendor/bin/ub-php', 'crap', '--format', 'json'],
        [$consumer . '/vendor/bin/ub-php', 'dry', '--format', 'json'],
        ['git', 'init', '-q'],
        [$consumer . '/vendor/bin/ub-php', 'check', '--changed', '--format', 'json'],
    ] as $command) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $consumer);
        if (!is_resource($process)) {
            throw new RuntimeException('cannot run consumer command');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('consumer command failed: ' . implode(' ', $command) . "\n" . $error);
        }
        if (in_array('check', $command, true)) {
            $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (($report['tool'] ?? null) !== 'check' || count($report['entries']) < 3) {
                throw new RuntimeException('installed binary returned invalid analysis');
            }
        }
        if (in_array('dry', $command, true) && count(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['candidates']) !== 1) {
            throw new RuntimeException('installed binary did not find the duplicate');
        }
    }
    echo "Composer consumer test passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($consumer);
}
