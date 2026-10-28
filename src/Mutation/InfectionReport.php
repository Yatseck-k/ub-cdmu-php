<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

use RuntimeException;
use UbModePhp\Paths;
use UbModePhp\Reports;

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

    /**
     * @param string $json
     * @param string $root
     * @param list<array<string, mixed>> $units
     * @param list<string> $files
     * @return array
     */
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
            if (
                !isset($stats[$field]) || (!is_int($stats[$field]) && !is_float($stats[$field]))
                || !is_finite((float) $stats[$field]) || $stats[$field] < 0 || $stats[$field] > 100
            ) {
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
                if (
                    !is_array($mutation) || !is_string($mutation['originalFilePath'] ?? null)
                    || !is_int($mutation['originalStartLine'] ?? null) || $mutation['originalStartLine'] < 1
                    || !is_string($mutation['mutatorName'] ?? null) || !is_string($row['diff'] ?? null)
                ) {
                    throw new RuntimeException('invalid Infection mutant');
                }
                $path = $mutation['originalFilePath'];
                $base = realpath($root);
                $real = realpath(str_starts_with($path, '/') ? $path : $root . '/' . $path);
                if ($base === false || $real === false || !Paths::isBelow($real, $base)) {
                    throw new RuntimeException('mutant file outside project root');
                }
                $file = substr($real, strlen($base) + 1);
                if (!in_array($file, $files, true)) {
                    throw new RuntimeException('Infection mutated a file outside the selected scope: ' . $file);
                }
                $line = $mutation['originalStartLine'];
                if ($line > substr_count(Reports::read($real), "\n") + 1) {
                    throw new RuntimeException('mutant line outside source file');
                }
                $mutants[] = [
                    'id' => hash('sha256', $file . ':' . $line . ':' . $mutation['mutatorName'] . ':' . $row['diff']),
                    'file' => $file, 'line' => $line, 'mutator' => $mutation['mutatorName'],
                    'status' => self::STATUSES[$group] ?? $group, 'diff' => $row['diff'],
                    'unit_id' => MutantBinder::unitId($units, $file, $line),
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
}
