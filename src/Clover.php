<?php

declare(strict_types=1);

namespace UbModePhp;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

final class Clover
{
    public static function read(string $path, string $root): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->load($path, LIBXML_NONET)) {
                throw new InvalidArgumentException('invalid Clover XML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $coverage = [];
        $realRoot = realpath($root);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//file') as $file) {
            if (!$file instanceof DOMElement || $file->getAttribute('name') === '') {
                continue;
            }
            $name = $file->getAttribute('name');
            $real = realpath(str_starts_with($name, '/') ? $name : $root . '/' . $name);
            if ($real === false || !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($realRoot) + 1));
            $coverage[$relative] ??= [];
            foreach ($xpath->query('./line', $file) as $line) {
                if (!$line instanceof DOMElement || $line->getAttribute('type') !== 'stmt') {
                    continue;
                }
                $number = $line->getAttribute('num');
                $count = $line->getAttribute('count');
                if (!ctype_digit($number) || !ctype_digit($count) || (int) $number < 1) {
                    throw new InvalidArgumentException('invalid Clover line in ' . $relative);
                }
                $coverage[$relative][(int) $number] = max($coverage[$relative][(int) $number] ?? 0, (int) $count);
            }
        }
        return $coverage;
    }

    public static function forUnit(array $unit, array $coverage): ?float
    {
        $relevant = [];
        foreach ($coverage[$unit['file']] ?? [] as $line => $hits) {
            if ($line >= $unit['start_line'] && $line <= $unit['end_line']) {
                $relevant[] = $hits;
            }
        }
        if ($relevant === []) {
            return null;
        }
        return 100 * count(array_filter($relevant, static fn (int $hits): bool => $hits > 0)) / count($relevant);
    }
}
