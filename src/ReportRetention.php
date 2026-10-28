<?php

declare(strict_types=1);

namespace UbModePhp;

use RuntimeException;

final class ReportRetention
{
    public static function id(): string
    {
        return sprintf('%016x', (int) (microtime(true) * 1000000)) . bin2hex(random_bytes(4));
    }

    public static function startRun(string $root, array $config): array
    {
        $base = Reports::directory($root, $config['reports']['directory'] . '/.mutation-runs');
        $id = self::id();
        $directory = $base . '/' . $id;
        if (!mkdir($directory)) {
            throw new RuntimeException('cannot create mutation run');
        }
        $lock = null;
        try {
            $lock = fopen($directory . '/run.lock', 'x+');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('cannot lock mutation run');
            }
            Reports::atomic($directory . '/run.json', Reports::json(['owner' => 'ub-cdmu-php', 'id' => $id, 'status' => 'active']));
            return ['directory' => $directory, 'lock' => $lock];
        } catch (\Throwable $error) {
            foreach (['run.json', 'run.lock'] as $name) {
                if (is_file($directory . '/' . $name) && !@unlink($directory . '/' . $name)) {
                    self::warning(new RuntimeException('cannot roll back mutation run file: ' . $directory . '/' . $name));
                }
            }
            if (is_resource($lock)) {
                fclose($lock);
            }
            if (!@rmdir($directory)) {
                self::warning(new RuntimeException('cannot roll back mutation run directory: ' . $directory));
            }
            throw $error;
        }
    }

    public static function finishRun(string $root, array $config, array $run, bool $published): void
    {
        try {
            if ($published) {
                self::remove($run['directory']);
            } else {
                Reports::atomic($run['directory'] . '/run.json', Reports::json([
                    'owner' => 'ub-cdmu-php', 'id' => basename($run['directory']), 'status' => 'failed',
                ]));
            }
        } catch (\Throwable $error) {
            self::warning($error);
        } finally {
            flock($run['lock'], LOCK_UN);
            fclose($run['lock']);
        }
        try {
            self::failedRuns($root, $config);
        } catch (\Throwable $error) {
            self::warning($error);
        }
    }

    public static function failedRuns(string $root, array $config): void
    {
        $keep = $config['mutator']['keep_failed_runs'];
        if ($keep === 0) {
            return;
        }
        $base = Reports::directory($root, $config['reports']['directory'] . '/.mutation-runs');
        $runs = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || !preg_match('/^[a-f0-9]{24}$/D', basename($directory))
                || is_link($directory . '/run.json') || is_link($directory . '/run.lock')) {
                continue;
            }
            $meta = json_decode(@file_get_contents($directory . '/run.json') ?: 'null', true);
            if (($meta['owner'] ?? null) !== 'ub-cdmu-php' || ($meta['id'] ?? null) !== basename($directory)
                || !is_file($directory . '/run.lock')) {
                continue;
            }
            if (($meta['status'] ?? null) === 'active' && filemtime($directory . '/run.lock') < time() - 60) {
                $probe = @fopen($directory . '/run.lock', 'r+');
                if ($probe !== false) {
                    try {
                        if (flock($probe, LOCK_EX | LOCK_NB)) {
                            $meta['status'] = 'failed';
                            Reports::atomic($directory . '/run.json', Reports::json($meta));
                        }
                    } finally {
                        fclose($probe);
                    }
                }
            }
            if (($meta['status'] ?? null) === 'failed') {
                $runs[] = $directory;
            }
        }
        self::newestFirst($runs);
        foreach (array_slice($runs, $keep) as $directory) {
            $lock = @fopen($directory . '/run.lock', 'r+');
            if ($lock === false) {
                continue;
            }
            try {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    self::remove($directory);
                }
            } finally {
                fclose($lock);
            }
        }
    }

    /** Caller holds the exclusive tool lock; readers hold its shared counterpart. */
    public static function generations(string $store, string $tool, int $keep): void
    {
        if ($keep === 0) {
            return;
        }
        $base = $store . '/' . $tool;
        $current = realpath($store . '/' . $tool . '-current');
        $generations = [];
        $allowed = $tool === 'uml' ? ['uml.json', 'uml.svg', 'uml.html'] : ['mutate.json', 'mutate.raw.json', 'mutate.log'];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || !preg_match('/^[a-f0-9]{24}$/D', basename($directory))
                || is_link($directory . '/manifest.json')) {
                continue;
            }
            $manifest = json_decode(@file_get_contents($directory . '/manifest.json') ?: 'null', true);
            if (!is_array($manifest) || !isset($manifest[$tool . '.json']) || array_diff(array_keys($manifest), $allowed) !== []) {
                continue;
            }
            $names = array_values(array_diff(scandir($directory), ['.', '..']));
            $expected = [...array_keys($manifest), 'manifest.json'];
            sort($names);
            sort($expected);
            if ($names !== $expected) {
                continue;
            }
            $valid = true;
            foreach ($manifest as $name => $hash) {
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || is_link($directory . '/' . $name) || !is_file($directory . '/' . $name)) {
                    $valid = false;
                    break;
                }
            }
            if ($valid && realpath($directory) !== $current) {
                $generations[] = $directory;
            }
        }
        self::newestFirst($generations);
        foreach (array_slice($generations, max(0, $keep - 1)) as $directory) {
            self::remove($directory);
        }
    }

    public static function remove(string $directory): void
    {
        if (is_link($directory)) {
            throw new RuntimeException('refusing to traverse retention symlink');
        }
        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                self::remove($file->getPathname());
            } elseif (!@unlink($file->getPathname())) {
                throw new RuntimeException('cannot remove generated artifact: ' . $file->getPathname());
            }
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException('cannot remove generated directory: ' . $directory);
        }
    }

    public static function warning(\Throwable $error): void
    {
        fwrite(STDERR, 'ub-php: report history cleanup warning: ' . $error->getMessage() . "\n");
    }

    private static function newestFirst(array &$paths): void
    {
        usort($paths, static fn (string $a, string $b): int =>
            (filemtime($b) <=> filemtime($a)) ?: strcmp($b, $a));
    }
}
