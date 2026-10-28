<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use RuntimeException;
use UbModePhp\Config;
use UbModePhp\Reports;
use UbModePhp\SourceFiles;

final class InfectionReport
{
    private const COUNTERS = [
        'killed' => 'killedCount', 'escaped' => 'escapedCount', 'uncovered' => 'notCoveredCount',
        'timeouted' => 'timeOutCount', 'errored' => 'errorCount', 'syntaxErrors' => 'syntaxErrorCount',
        'ignored' => 'ignoredCount',
    ];
    private const STATUSES = [
        'escaped' => 'survived', 'timeouted' => 'timeout', 'errored' => 'error', 'syntaxErrors' => 'syntax_error',
    ];

    public static function normalize(string $json, string $root, array $units, array $files): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['stats'] ?? null)) {
            throw new RuntimeException('invalid Infection JSON: missing stats');
        }
        $stats = $data['stats'];
        foreach (array_merge(['totalMutantsCount', 'skippedCount'], array_values(self::COUNTERS)) as $field) {
            if (!isset($stats[$field]) || !is_int($stats[$field]) || $stats[$field] < 0) {
                throw new RuntimeException('invalid Infection counter: ' . $field);
            }
        }
        foreach (['msi', 'coveredCodeMsi', 'mutationCodeCoverage'] as $field) {
            if (!isset($stats[$field]) || (!is_int($stats[$field]) && !is_float($stats[$field]))
                || !is_finite((float) $stats[$field]) || $stats[$field] < 0 || $stats[$field] > 100) {
                throw new RuntimeException('invalid Infection metric: ' . $field);
            }
        }
        $mutants = [];
        foreach (self::COUNTERS as $group => $counter) {
            $rows = $data[$group] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) !== $stats[$counter]) {
                throw new RuntimeException('incomplete Infection group: ' . $group);
            }
            foreach ($rows as $row) {
                $mutation = $row['mutator'] ?? null;
                if (!is_array($mutation) || !is_string($mutation['originalFilePath'] ?? null)
                    || !is_int($mutation['originalStartLine'] ?? null) || $mutation['originalStartLine'] < 1
                    || !is_string($mutation['mutatorName'] ?? null) || !is_string($row['diff'] ?? null)) {
                    throw new RuntimeException('invalid Infection mutant');
                }
                $path = $mutation['originalFilePath'];
                $base = realpath($root);
                $real = realpath(str_starts_with($path, '/') ? $path : $root . '/' . $path);
                if ($base === false || $real === false || !str_starts_with($real, $base . '/')) {
                    throw new RuntimeException('mutant file outside project root');
                }
                $file = substr($real, strlen($base) + 1);
                if (!in_array($file, $files, true)) {
                    throw new RuntimeException('Infection mutated a file outside the selected scope: ' . $file);
                }
                $line = $mutation['originalStartLine'];
                if ($line > substr_count(file_get_contents($real), "\n") + 1) {
                    throw new RuntimeException('mutant line outside source file');
                }
                $matching = array_values(array_filter($units, static fn (array $unit): bool =>
                    $unit['file'] === $file && $line >= $unit['start_line'] && $line <= $unit['end_line']));
                $inner = array_values(array_filter($matching, static function (array $unit) use ($matching): bool {
                    foreach ($matching as $other) {
                        if ($other['start_line'] >= $unit['start_line'] && $other['end_line'] <= $unit['end_line']
                            && ($other['start_line'] > $unit['start_line'] || $other['end_line'] < $unit['end_line'])) {
                            return false;
                        }
                    }
                    return true;
                }));
                $mutants[] = [
                    'id' => hash('sha256', $file . ':' . $line . ':' . $mutation['mutatorName'] . ':' . $row['diff']),
                    'file' => $file, 'line' => $line, 'mutator' => $mutation['mutatorName'],
                    'status' => self::STATUSES[$group] ?? $group, 'diff' => $row['diff'],
                    'unit_id' => count($inner) === 1 ? $inner[0]['id'] : null,
                ];
            }
        }
        $counted = count($mutants) + $stats['skippedCount'];
        if ($counted !== $stats['totalMutantsCount']) {
            throw new RuntimeException('Infection total does not match mutant groups');
        }
        usort($mutants, static fn (array $a, array $b): int =>
            [$a['file'], $a['line'], $a['mutator'], $a['id']] <=> [$b['file'], $b['line'], $b['mutator'], $b['id']]);
        $summary = ['total' => $stats['totalMutantsCount'], 'skipped' => $stats['skippedCount']];
        foreach (self::COUNTERS as $group => $counter) {
            $summary[self::STATUSES[$group] ?? $group] = $stats[$counter];
        }
        $eligible = $summary['total'] - $summary['ignored'] - $summary['skipped'];
        $summary['msi'] = $eligible === 0 ? null : (float) $stats['msi'];
        $covered = $eligible - $summary['uncovered'];
        $summary['covered_msi'] = $covered === 0 ? null : (float) $stats['coveredCodeMsi'];
        $summary['mutation_coverage'] = $eligible === 0 ? null : (float) $stats['mutationCodeCoverage'];
        return ['summary' => $summary, 'mutants' => $mutants];
    }

    public static function fingerprints(string $root, array $config): array
    {
        $root = realpath($root) ?: throw new RuntimeException('project root does not exist');
        $sourceFiles = SourceFiles::find($root, $config, false);
        $mandatory = [];
        $roots = [];
        $exclude = [Config::enginePath($root, $config['reports']['directory'], 'reports.directory', false)];
        foreach ($config['mutator']['input_exclude'] as $path) {
            $exclude[] = Config::enginePath($root, $path, 'mutator.input_exclude', false);
        }
        foreach (['composer.json', 'composer.lock', $config['mutator']['configuration']] as $path) {
            if (is_file($root . '/' . $path)) {
                $mandatory[] = Config::enginePath($root, $path, 'mutation input');
            }
        }
        $settings = [];
        if (is_file($root . '/' . $config['mutator']['configuration'])) {
            $settings = \ColinODell\Json5\Json5Decoder::decode(file_get_contents($root . '/' . $config['mutator']['configuration']), true);
            if (!is_array($settings)) {
                throw new \InvalidArgumentException('Infection configuration must be an object');
            }
            if (isset($settings['bootstrap'])) {
                $mandatory[] = Config::enginePath($root, $settings['bootstrap'], 'Infection bootstrap');
            }
            foreach (($settings['logs'] ?? []) as $path) {
                if (is_string($path)) {
                    self::outputPath($root, $path, $exclude);
                }
            }
            if (isset($settings['tmpDir']) && is_string($settings['tmpDir'])) {
                self::outputPath($root, $settings['tmpDir'], $exclude);
            }
        }
        $configDir = Config::enginePath($root, $settings['phpUnit']['configDir'] ?? '.', 'phpUnit.configDir');
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
            $path = $configDir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $mandatory[] = $path;
            $xml = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                if (!$xml->loadXML(file_get_contents($path), LIBXML_NONET)) {
                    throw new \InvalidArgumentException('invalid PHPUnit XML input: ' . $name);
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($xml);
            foreach ($xpath->query('//testsuite/directory | //testsuite/file') as $node) {
                $value = trim($node->textContent);
                $roots[] = Config::enginePath($root, str_starts_with($value, '/') ? $value : $configDir . '/' . $value, 'PHPUnit testsuite');
            }
            $bootstrap = $xml->documentElement->getAttribute('bootstrap');
            if ($bootstrap !== '') {
                $mandatory[] = Config::enginePath($root, str_starts_with($bootstrap, '/') ? $bootstrap : $configDir . '/' . $bootstrap, 'PHPUnit bootstrap');
            }
            foreach (['cacheDirectory', 'cacheResultFile'] as $key) {
                $value = $xml->documentElement->getAttribute($key);
                if ($value !== '') {
                    self::outputPath($root, str_starts_with($value, '/') ? $value : $configDir . '/' . $value, $exclude);
                }
            }
            foreach ($xpath->query('//logging//* | //coverage/report//*') as $node) {
                foreach (['outputFile', 'outputDirectory', 'target'] as $key) {
                    if ($node->hasAttribute($key)) {
                        $value = $node->getAttribute($key);
                        self::outputPath($root, str_starts_with($value, '/') ? $value : $configDir . '/' . $value, $exclude);
                    }
                }
            }
        }
        foreach (['tests', 'test', 'spec', 'fixtures'] as $directory) {
            if (is_dir($root . '/' . $directory)) {
                $roots[] = Config::enginePath($root, $directory, 'test input directory');
            }
        }
        foreach ($config['mutator']['input_paths'] as $path) {
            $roots[] = Config::enginePath($root, $path, 'mutator.input_paths');
        }
        $paths = [];
        foreach (array_unique($roots) as $path) {
            if (self::excluded($path, $exclude)) {
                continue;
            }
            if (is_file($path)) {
                $paths[$path] = true;
                continue;
            }
            $filter = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !$file->isLink()
                    && !in_array($file->getFilename(), ['vendor', 'node_modules', '.git', '.metrics', '.phpunit.cache'], true)
                    && !self::excluded($file->getPathname(), $exclude),
            );
            foreach (new RecursiveIteratorIterator($filter) as $file) {
                if ($file->isFile()) {
                    $paths[$file->getPathname()] = true;
                }
            }
        }
        // Mandatory inputs remain protected even if an exclusion overlaps them.
        foreach (array_merge($mandatory, array_map(static fn (string $file): string => $root . '/' . $file, $sourceFiles)) as $path) {
            $paths[$path] = true;
        }
        $inputs = [];
        foreach (array_keys($paths) as $path) {
            $real = Config::enginePath($root, $path, 'mutation input');
            $relative = substr($real, strlen($root) + 1);
            if (!isset($inputs[$relative])) {
                $hash = hash_file('sha256', $real);
                if ($hash === false) {
                    throw new RuntimeException('cannot fingerprint mutation input: ' . $relative);
                }
                $inputs[$relative] = $hash;
            }
        }
        ksort($inputs, SORT_STRING);
        $source = array_intersect_key($inputs, array_fill_keys($sourceFiles, true));
        $settings = [
            'fingerprint_format' => 2, 'version' => $config['version'], 'project' => $config['project'],
            'mutator' => array_intersect_key($config['mutator'], array_flip([
                'executable', 'configuration', 'input_paths', 'input_exclude',
            ])),
        ];
        return ['source' => $source, 'inputs' => $inputs, 'configuration' => hash('sha256', Reports::json($settings))];
    }

    private static function outputPath(string $root, string $path, array &$exclude): void
    {
        try {
            $exclude[] = Config::enginePath($root, $path, 'generated output', false);
        } catch (\InvalidArgumentException) {
            // External outputs are outside the input manifest already.
        }
    }

    private static function excluded(string $path, array $exclude): bool
    {
        foreach ($exclude as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
