<?php

declare(strict_types=1);

namespace UbModePhp;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class Config
{
    private const DEFAULTS = [
        'version' => 1,
        'project' => [
            'language' => 'php',
            'source' => ['src', 'app'],
            'exclude' => ['vendor', 'tests', 'var', 'storage', 'bootstrap/cache', '.metrics'],
        ],
        'crapper' => [
            'enabled' => true,
            'threshold' => 10,
            'coverage' => ['format' => 'clover', 'path' => 'coverage.xml', 'missing' => 'unknown'],
        ],
        'dryer' => ['enabled' => true, 'threshold' => 0.82, 'min_lines' => 4, 'min_nodes' => 20],
        'mutator' => [
            'executable' => 'vendor/bin/infection', 'configuration' => 'infection.json5',
            'threads' => 1, 'process_timeout' => 600, 'min_msi' => null,
            'input_paths' => [], 'input_exclude' => [], 'keep_failed_runs' => 5,
        ],
        'uml' => ['view_limit' => 200],
        'reports' => ['formats' => ['json', 'edn'], 'directory' => '.metrics', 'keep_generations' => 5],
        'quality' => ['fail_on' => ['exceeded_crap_threshold']],
    ];

    public static function load(string $root, ?string $path = null): array
    {
        $file = $path ?? $root . '/.ub-mode.yaml';
        $config = self::DEFAULTS;
        if (file_exists($file)) {
            if (!is_file($file)) {
                throw new InvalidArgumentException('configuration path must be a file');
            }
            try {
                $override = Yaml::parseFile($file);
            } catch (ParseException $error) {
                throw new InvalidArgumentException('invalid YAML configuration: ' . $error->getMessage(), 0, $error);
            }
            if (!is_array($override) || array_is_list($override)) {
                throw new InvalidArgumentException('configuration must be a mapping');
            }
            $config = self::merge($config, $override);
        }
        self::validate($config);
        return $config;
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (!array_key_exists($key, $base)) {
                throw new InvalidArgumentException('unknown configuration key: ' . $key);
            }
            if (is_array($base[$key]) && !array_is_list($base[$key])) {
                if (!is_array($value) || array_is_list($value)) {
                    throw new InvalidArgumentException('configuration ' . $key . ' must be a mapping');
                }
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function relativePath(mixed $value): string
    {
        if (
            !is_string($value) || $value === '' || str_starts_with($value, '/')
            || str_contains($value, '\\') || str_contains($value, "\0")
            || array_intersect(explode('/', $value), ['', '.', '..']) !== []
        ) {
            throw new InvalidArgumentException('path must be relative to the project');
        }
        return $value;
    }

    public static function projectPath(string $root, string $relative): string
    {
        self::relativePath($relative);
        $base = realpath($root);
        $path = realpath($root . '/' . $relative);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('path is missing or outside project root: ' . $relative);
        }
        return $path;
    }

    /** Normalize Infection/PHPUnit paths, including ./src and src/, inside the project. */
    public static function enginePath(string $root, mixed $value, string $field, bool $mustExist = true): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
            throw new InvalidArgumentException($field . ' must be a non-empty project path string');
        }
        $base = realpath($root);
        if ($base === false) {
            throw new InvalidArgumentException('project root does not exist');
        }
        $candidate = str_starts_with($value, '/') ? $value : $base . '/' . $value;
        $parts = [];
        foreach (explode('/', $candidate) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        $candidate = '/' . implode('/', $parts);
        $ancestor = $candidate;
        $tail = [];
        while (!file_exists($ancestor) && !is_link($ancestor) && dirname($ancestor) !== $ancestor) {
            array_unshift($tail, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $resolved = realpath($ancestor);
        $path = $resolved === false ? '' : $resolved . ($tail === [] ? '' : '/' . implode('/', $tail));
        if ($path === '' || ($path !== $base && !str_starts_with($path, $base . '/'))
            || ($mustExist && !file_exists($path))) {
            throw new InvalidArgumentException($field . ' is missing or outside project root: ' . $value);
        }
        return $path;
    }

    private static function positiveNumber(mixed $value, string $name): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new InvalidArgumentException($name . ' must be positive');
        }
    }

    private static function validate(array &$config): void
    {
        if ($config['version'] !== 1 || $config['project']['language'] !== 'php') {
            throw new InvalidArgumentException('only version 1 PHP configuration is supported');
        }
        foreach (['source', 'exclude'] as $key) {
            if (!is_array($config['project'][$key]) || !array_is_list($config['project'][$key])) {
                throw new InvalidArgumentException('project.' . $key . ' must be a list');
            }
            $config['project'][$key] = array_map(self::relativePath(...), $config['project'][$key]);
        }
        foreach (['crapper', 'dryer'] as $group) {
            if (!is_bool($config[$group]['enabled'])) {
                throw new InvalidArgumentException($group . '.enabled must be a boolean');
            }
        }
        self::positiveNumber($config['crapper']['threshold'], 'crapper.threshold');
        $coverage = $config['crapper']['coverage'];
        if ($coverage['format'] !== 'clover' || $coverage['missing'] !== 'unknown') {
            throw new InvalidArgumentException('only Clover coverage with unknown missing values is supported');
        }
        $config['crapper']['coverage']['path'] = self::relativePath($coverage['path']);
        $threshold = $config['dryer']['threshold'];
        if (
            (!is_int($threshold) && !is_float($threshold))
            || !is_finite((float) $threshold) || $threshold < 0 || $threshold > 1
        ) {
            throw new InvalidArgumentException('dryer.threshold must be between 0 and 1');
        }
        foreach (['min_lines', 'min_nodes'] as $key) {
            if (!is_int($config['dryer'][$key]) || $config['dryer'][$key] < 1) {
                throw new InvalidArgumentException('dryer minimums must be positive integers');
            }
        }
        foreach (['executable', 'configuration'] as $key) {
            $config['mutator'][$key] = self::relativePath($config['mutator'][$key]);
        }
        if (str_contains($config['mutator']['configuration'], '/')) {
            throw new InvalidArgumentException('mutator.configuration must be in the project root');
        }
        foreach (['threads', 'process_timeout'] as $key) {
            if (!is_int($config['mutator'][$key]) || $config['mutator'][$key] < 1) {
                throw new InvalidArgumentException('mutator.' . $key . ' must be a positive integer');
            }
        }
        foreach (['input_paths', 'input_exclude'] as $key) {
            if (!is_array($config['mutator'][$key]) || !array_is_list($config['mutator'][$key])) {
                throw new InvalidArgumentException('mutator.' . $key . ' must be a list');
            }
            $config['mutator'][$key] = array_map(self::relativePath(...), $config['mutator'][$key]);
        }
        foreach ([['mutator', 'keep_failed_runs'], ['reports', 'keep_generations']] as [$group, $key]) {
            if (!is_int($config[$group][$key]) || $config[$group][$key] < 0) {
                throw new InvalidArgumentException($group . '.' . $key . ' must be a non-negative integer');
            }
        }
        $minimum = $config['mutator']['min_msi'];
        if ($minimum !== null && ((!is_int($minimum) && !is_float($minimum))
            || !is_finite((float) $minimum) || $minimum < 0 || $minimum > 100)) {
            throw new InvalidArgumentException('mutator.min_msi must be null or 0..100');
        }
        if (!is_int($config['uml']['view_limit']) || $config['uml']['view_limit'] < 1) {
            throw new InvalidArgumentException('uml.view_limit must be a positive integer');
        }
        $config['reports']['directory'] = self::relativePath($config['reports']['directory']);
        if (!self::subset($config['reports']['formats'], ['json', 'edn'])) {
            throw new InvalidArgumentException('reports.formats must contain json and/or edn');
        }
        if (!self::subset($config['quality']['fail_on'], ['exceeded_crap_threshold'])) {
            throw new InvalidArgumentException('unsupported quality.fail_on entry');
        }
    }

    private static function subset(mixed $values, array $allowed): bool
    {
        if (!is_array($values) || !array_is_list($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                return false;
            }
        }
        return true;
    }
}
