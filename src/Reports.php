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
