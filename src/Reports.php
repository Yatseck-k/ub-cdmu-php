<?php

declare(strict_types=1);

namespace UbModePhp;

use RuntimeException;

final class Reports
{
    public static function json(array $report): string
    {
        $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION;
        return json_encode(self::ordered($report), $options) . "\n";
    }

    public static function crapEdn(array $entries): string
    {
        $records = [];
        foreach ($entries as $entry) {
            if (
                !in_array($entry['kind'], ['function', 'method'], true)
                || str_contains($entry['namespace'], 'anonymous@')
            ) {
                continue;
            }
            $records[] = '{:name ' . self::string($entry['name']) . ' :namespace ' . self::string($entry['namespace'])
                . ' :complexity ' . $entry['complexity'] . ' :coverage ' . self::number($entry['coverage'])
                . ' :crap ' . self::number($entry['crap']) . '}';
        }
        return '{:entries [' . implode(' ', $records) . "]}\n";
    }

    public static function dryEdn(array $candidates): string
    {
        $records = [];
        foreach ($candidates as $candidate) {
            $spans = [];
            foreach (['left', 'right'] as $side) {
                $span = $candidate[$side];
                $spans[] = ':' . $side . ' {:file ' . self::string($span['file'])
                    . ' :start-line ' . $span['start_line'] . ' :end-line ' . $span['end_line'] . '}';
            }
            $records[] = '{:score ' . self::number($candidate['score']) . ' :language "php" ' . implode(' ', $spans)
                . ' :left-nodes ' . $candidate['left_nodes'] . ' :right-nodes ' . $candidate['right_nodes'] . '}';
        }
        return '{:candidates [' . implode(' ', $records) . "]}\n";
    }

    public static function atomic(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('cannot create report directory');
        }
        $temporary = tempnam($directory, '.ub-php-');
        if ($temporary === false) {
            throw new RuntimeException('cannot create temporary report');
        }
        try {
            if (file_put_contents($temporary, $content) === false || !rename($temporary, $path)) {
                throw new RuntimeException('cannot write report');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Publish a generation through one atomic pointer; aliases always resolve together. */
    public static function bundle(string $root, string $directory, string $tool, array $artifacts, int $keep = 5): array
    {
        $base = self::directory($root, $directory);
        $store = self::directory($root, $directory . '/.cdmu');
        $lock = fopen($store . '/' . $tool . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('cannot lock report publication');
        }
        $created = [];
        $generations = self::directory($root, $directory . '/.cdmu/' . $tool);
        $generation = $generations . '/' . ReportRetention::id();
        $pointer = $store . '/.' . $tool . '-' . bin2hex(random_bytes(8));
        try {
            if (!mkdir($generation, 0777, true)) {
                throw new RuntimeException('cannot create report generation');
            }
            $manifest = [];
            foreach ($artifacts as $name => $content) {
                if (basename($name) !== $name || $name === '' || $name === '.' || $name === '..') {
                    throw new RuntimeException('invalid artifact name');
                }
                self::atomic($generation . '/' . $name, $content);
                $manifest[$name] = hash('sha256', $content);
            }
            self::atomic($generation . '/manifest.json', self::json($manifest));
            foreach (array_keys($artifacts) as $name) {
                $alias = $base . '/' . $name;
                $target = '.cdmu/' . $tool . '-current/' . $name;
                if (is_link($alias)) {
                    if (readlink($alias) !== $target) {
                        throw new RuntimeException('report alias is not managed by CDMU: ' . $name);
                    }
                } elseif (file_exists($alias)) {
                    throw new RuntimeException('refusing to replace unmanaged report: ' . $name);
                } else {
                    if (!symlink($target, $alias)) {
                        throw new RuntimeException('cannot create report alias: ' . $name);
                    }
                    $created[] = $alias;
                }
            }
            if (file_exists($store . '/' . $tool . '-current') && !is_link($store . '/' . $tool . '-current')) {
                throw new RuntimeException('report pointer is not a symlink');
            }
            if (!symlink($tool . '/' . basename($generation), $pointer)
                || !rename($pointer, $store . '/' . $tool . '-current')) {
                throw new RuntimeException('cannot publish report generation');
            }
            clearstatcache(true);
            try {
                ReportRetention::generations($store, $tool, $keep);
            } catch (\Throwable $error) {
                ReportRetention::warning($error);
            }
            return array_map(static fn (string $name): string => $directory . '/' . $name, array_keys($artifacts));
        } catch (\Throwable $error) {
            foreach ($created as $alias) {
                unlink($alias);
            }
            if (is_link($pointer)) {
                unlink($pointer);
            }
            foreach (glob($generation . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($generation)) {
                rmdir($generation);
            }
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function readBundle(string $root, string $directory, string $tool, string $name): array
    {
        $store = Config::projectPath($root, $directory . '/.cdmu');
        $lockPath = $store . '/' . $tool . '.lock';
        if (is_link($lockPath)) {
            throw new \InvalidArgumentException('unmanaged snapshot lock');
        }
        $lock = @fopen($lockPath, 'r');
        if ($lock === false || !flock($lock, LOCK_SH)) {
            throw new \InvalidArgumentException('cannot read snapshot lock');
        }
        try {
            $alias = $root . '/' . $directory . '/' . $name;
            if (!is_link($alias) || readlink($alias) !== '.cdmu/' . $tool . '-current/' . $name) {
                throw new \InvalidArgumentException('unverified snapshot alias');
            }
            clearstatcache(true);
            $real = Config::projectPath($root, $directory . '/' . $name);
            $manifestBytes = @file_get_contents(dirname($real) . '/manifest.json');
            $content = @file_get_contents($real);
            if ($manifestBytes === false || $content === false) {
                throw new \InvalidArgumentException('snapshot is unreadable');
            }
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest[$name] ?? null) !== hash('sha256', $content)) {
                throw new \InvalidArgumentException('snapshot checksum mismatch');
            }
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('snapshot must be an object');
            }
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function directory(string $root, string $relative): string
    {
        Config::relativePath($relative);
        $base = realpath($root);
        if ($base === false) {
            throw new RuntimeException('project root missing');
        }
        $path = $base;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (!file_exists($path) && !is_link($path) && !mkdir($path)) {
                throw new RuntimeException('cannot create report directory');
            }
            $real = realpath($path);
            if ($real === false || !is_dir($real) || !str_starts_with($real, $base . '/')) {
                throw new RuntimeException('report directory outside project root');
            }
        }
        return $path;
    }

    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::ordered($item);
        }
        return $value;
    }

    private static function string(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function number(int|float|null $value): string
    {
        return $value === null ? 'nil' : json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
