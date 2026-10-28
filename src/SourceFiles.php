<?php

declare(strict_types=1);

namespace UbModePhp;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class SourceFiles
{
    /**
     * @param string $root
     * @param array<string, mixed> $config
     * @param bool $changed
     * @return list<string>
     */
    public static function find(string $root, array $config, bool $changed): array
    {
        $config['project']['exclude'][] = $config['reports']['directory'];
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException('root directory does not exist');
        }
        // An explicitly configured external report symlink is allowed; exclude the directory only inside the project.
        $reportPath = realpath($realRoot . '/' . $config['reports']['directory']);
        if ($reportPath !== false && Paths::isBelow($reportPath, $realRoot)) {
            $config['project']['exclude'][] = substr($reportPath, strlen($realRoot) + 1);
        }
        $paths = $changed ? self::changed($realRoot) : self::scan($realRoot, $config['project']['source']);
        $selected = [];
        foreach ($paths as $path) {
            $real = realpath($realRoot . '/' . $path);
            if (
                $real === false || !Paths::isBelow($real, $realRoot)
                || !is_file($real) || pathinfo($path, PATHINFO_EXTENSION) !== 'php'
            ) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($realRoot) + 1));
            if (!self::included($relative, $config['project']['source'], $config['project']['exclude'])) {
                continue;
            }
            $selected[$relative] = true;
        }
        $result = array_keys($selected);
        sort($result, SORT_STRING);
        return $result;
    }

    private static function scan(string $root, array $sources): array
    {
        $paths = [];
        foreach ($sources as $source) {
            $directory = $root . '/' . $source;
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        return $paths;
    }

    private static function changed(string $root): array
    {
        // Outside a work tree `git diff` falls back to --no-index and prints its usage text.
        $probe = proc_open(
            ['git', 'rev-parse', '--is-inside-work-tree'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $probePipes,
            $root,
        );
        if (is_resource($probe)) {
            $inside = trim((string) stream_get_contents($probePipes[1]));
            fclose($probePipes[1]);
            fclose($probePipes[2]);
            if (proc_close($probe) !== 0 || $inside !== 'true') {
                throw new RuntimeException('--changed requires a Git repository; run without --changed');
            }
        }
        $commands = [
            ['git', 'diff', '--name-only', '--diff-filter=ACMR', '-z'],
            ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z'],
            ['git', 'ls-files', '--others', '--exclude-standard', '-z'],
        ];
        $paths = [];
        foreach ($commands as $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            if (!is_resource($process)) {
                throw new RuntimeException('Git changed-file selection failed');
            }
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException(trim($error) ?: 'Git changed-file selection failed');
            }
            array_push($paths, ...array_filter(explode("\0", $output), static fn (string $part): bool => $part !== ''));
        }
        return $paths;
    }

    private static function included(string $path, array $sources, array $exclusions): bool
    {
        $inSource = false;
        foreach ($sources as $source) {
            if (Paths::isAtOrBelow($path, $source)) {
                $inSource = true;
                break;
            }
        }
        if (!$inSource) {
            return false;
        }
        foreach ($exclusions as $prefix) {
            if (
                Paths::isAtOrBelow($path, $prefix)
                || str_contains('/' . $path, '/' . $prefix . '/')
            ) {
                return false;
            }
        }
        return true;
    }
}
