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
        'reports' => ['formats' => ['json', 'edn'], 'directory' => '.metrics'],
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
