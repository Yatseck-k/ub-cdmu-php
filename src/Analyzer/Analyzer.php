<?php

declare(strict_types=1);

namespace UbModePhp\Analyzer;

use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\ParserFactory;
use UbModePhp\Paths;

final class Analyzer
{
    /**
     * @param string $root
     * @param list<mixed> $files
     * @param (callable(int, int, string): void)|null $progress
     * @return array{
     *     schema_version: string,
     *     units: list<array<string, mixed>>,
     *     diagnostics: list<array<string, string>>
     * }
     */
    public function analyze(string $root, array $files, ?callable $progress = null): array
    {
        $discoverer = new UnitDiscoverer();
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException('root directory does not exist');
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $diagnostics = [];
        $seen = [];
        $totalFiles = count($files);
        foreach ($files as $index => $file) {
            if (
                !is_string($file) || $file === '' || str_starts_with($file, '/')
                || str_contains($file, '\\') || str_contains($file, "\0")
            ) {
                throw new InvalidArgumentException('files must contain relative POSIX paths');
            }
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $parts = explode('/', $file);
            if (
                in_array('..', $parts, true) || in_array('.', $parts, true)
                || pathinfo($file, PATHINFO_EXTENSION) !== 'php'
            ) {
                throw new InvalidArgumentException('invalid PHP file path');
            }
            $realFile = realpath($realRoot . '/' . $file);
            if (
                $realFile === false || !Paths::isBelow($realFile, $realRoot)
                || !is_file($realFile)
            ) {
                $diagnostics[] = ['file' => $file, 'message' => 'file is missing or outside project root'];
                if ($progress !== null) {
                    $progress($index + 1, $totalFiles, $file);
                }
                continue;
            }
            try {
                $source = file_get_contents($realFile);
                if ($source === false) {
                    throw new InvalidArgumentException('file cannot be read');
                }
                $nodes = $parser->parse($source) ?? [];
                $discoverer->discover($nodes, $file);
            } catch (Error | InvalidArgumentException $error) {
                $diagnostics[] = ['file' => $file, 'message' => $error->getMessage()];
            }
            if ($progress !== null) {
                $progress($index + 1, $totalFiles, $file);
            }
        }
        $units = $discoverer->units();
        usort($units, static fn (array $a, array $b): int =>
            [$a['file'], $a['start_line'], $a['id']] <=> [$b['file'], $b['start_line'], $b['id']]);
        usort($diagnostics, static fn (array $a, array $b): int =>
            [$a['file'], $a['message']] <=> [$b['file'], $b['message']]);
        return ['schema_version' => '1.0', 'units' => $units, 'diagnostics' => $diagnostics];
    }
}
