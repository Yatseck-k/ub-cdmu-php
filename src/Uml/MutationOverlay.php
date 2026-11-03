<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use InvalidArgumentException;
use JsonException;
use UbModePhp\Mutation\InfectionRunner;
use UbModePhp\Mutation\InputFingerprint;
use UbModePhp\Reports;

final class MutationOverlay
{
    /**
     * @param string $root
     * @param array<string, mixed> $config
     * @param list<string> $files
     * @param ?callable $fingerprints
     * @return array{0: array{status: string, reason: string}, 1: list<array<string, mixed>>} overlay, mutants
     */
    public static function load(string $root, array $config, array $files, ?callable $fingerprints = null): array
    {
        $path = $root . '/' . $config['reports']['directory'] . '/mutate.json';
        if (!file_exists($path)) {
            return self::none('unavailable', 'no mutation snapshot; tests were not run');
        }
        try {
            $report = Reports::readBundle($root, $config['reports']['directory'], 'mutate', 'mutate.json');
        } catch (InvalidArgumentException | JsonException $error) {
            return self::none('unavailable', $error->getMessage());
        }
        if (
            ($report['tool'] ?? null) !== 'mutate' || ($report['schema_version'] ?? null) !== '1.0'
            || ($report['engine']['version'] ?? null) !== InfectionRunner::VERSION
            || !in_array($report['status'] ?? null, ['PASS', 'WARN', 'FAIL'], true)
            || ($report['scope']['files'] ?? null) !== $files
        ) {
            return self::none('stale', 'mutation scope or format no longer match');
        }
        if (!is_array($report['mutants'] ?? null) || !array_is_list($report['mutants'])) {
            return self::none('unavailable', 'invalid snapshot mutants');
        }
        $selected = array_fill_keys($files, true);
        foreach ($report['mutants'] as $mutant) {
            if (
                !is_array($mutant) || !array_key_exists('unit_id', $mutant)
                || ($mutant['unit_id'] !== null && !is_string($mutant['unit_id']))
                || !is_string($mutant['file'] ?? null) || !isset($selected[$mutant['file']])
                || !is_int($mutant['line'] ?? null) || $mutant['line'] < 1
                || !in_array($mutant['status'] ?? null, ['killed', 'survived', 'uncovered', 'timeout', 'error', 'syntax_error', 'ignored'], true)
            ) {
                return self::none('unavailable', 'invalid snapshot mutant');
            }
        }
        if (!is_array($report['fingerprints'] ?? null)) {
            return self::none('unavailable', 'invalid snapshot fingerprints');
        }
        // Errors in our own computation must not be disguised as stale user data.
        $current = ($fingerprints ?? InputFingerprint::compute(...))($root, $config);
        if (Reports::json($report['fingerprints'] ?? null) !== Reports::json($current)) {
            return self::none('stale', 'source/tests/configuration no longer match');
        }
        return [['status' => 'available', 'reason' => 'verified current mutation snapshot'], $report['mutants']];
    }

    /**
     * A mutant without a unique unit is attributed only to a class and method that are themselves unambiguous.
     *
     * @param array $nodes
     * @param list<array<string, mixed>> $units
     * @param list<array<string, mixed>> $mutants
     * @return array{0: array, 1: array, 2: list<string>} mutants by class id, by member key, diagnostics
     */
    public static function bind(array $nodes, array $units, array $mutants): array
    {
        $classes = [];
        $classesByFile = [];
        $methods = [];
        $unitsById = [];
        $owners = [];
        foreach ($nodes as $node) {
            if (!$node['external'] && $node['kind'] !== 'ambiguous') {
                $classes[$node['id']] = $node;
                $classesByFile[$node['file']][] = $node;
            }
        }
        foreach ($units as $unit) {
            $unitsById[$unit['id']] = $unit;
            if ($unit['kind'] === 'method') {
                $methods[$unit['namespace']][] = $unit;
            }
        }
        foreach ($units as $unit) {
            $containing = array_values(array_filter($methods[$unit['namespace']] ?? [], static fn (array $method): bool =>
                $method['file'] === $unit['file'] && $method['start_line'] <= $unit['start_line'] && $method['end_line'] >= $unit['end_line']));
            if (count($containing) === 1) {
                $owners[$unit['id']] = $containing[0];
            }
        }
        $byClass = [];
        $byMember = [];
        $diagnostics = [];
        foreach ($mutants as $mutant) {
            $unit = $unitsById[$mutant['unit_id'] ?? ''] ?? null;
            $class = $unit === null ? null : ($classes[$unit['namespace']] ?? null);
            $method = $unit === null ? null : ($owners[$unit['id']] ?? null);
            if ($unit === null) {
                $matching = array_values(array_filter($classesByFile[$mutant['file']] ?? [], static fn (array $node): bool =>
                    $node['start_line'] <= $mutant['line'] && $node['end_line'] >= $mutant['line']));
                if (count($matching) === 1) {
                    $class = $matching[0];
                    $matchingMethods = array_values(array_filter($methods[$class['id']] ?? [], static fn (array $item): bool =>
                        $item['file'] === $mutant['file'] && $item['start_line'] <= $mutant['line'] && $item['end_line'] >= $mutant['line']));
                    $method = count($matchingMethods) === 1 ? $matchingMethods[0] : null;
                }
                $diagnostics[] = 'Ambiguous mutation unit at ' . $mutant['file'] . ':' . $mutant['line']
                    . '; assigned only to unambiguous containing class/method.';
            }
            if ($class !== null) {
                $byClass[$class['id']][] = $mutant;
                if ($method !== null) {
                    $key = $class['id'] . "\0" . $method['file'] . "\0" . $method['name'] . "\0" . $method['start_line'];
                    $byMember[$key][] = $mutant;
                }
            }
        }
        return [$byClass, $byMember, array_values(array_unique($diagnostics))];
    }

    /**
     * @param string $status
     * @param string $reason
     * @return array{array{status: string, reason: string}, list<array<string, mixed>>}
     */
    private static function none(string $status, string $reason): array
    {
        return [['status' => $status, 'reason' => $reason], []];
    }
}
