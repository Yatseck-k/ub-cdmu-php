<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use RuntimeException;
use UbModePhp\Analyzer\Analyzer;
use UbModePhp\Clover;
use UbModePhp\Config;
use UbModePhp\Metrics;
use UbModePhp\Mutation\InfectionReport;
use UbModePhp\Mutation\InfectionRunner;
use UbModePhp\Progress;
use UbModePhp\Reports;
use UbModePhp\SourceFiles;
use UbModePhp\TextReport;

final class UmlCommand
{
    public static function run(string $root, array $config, array $options, Progress $progress): int
    {
        $files = SourceFiles::find($root, $config, false);
        $progress->stage(1, 'Extracting PHP topology', max(1, count($files)));
        $graph = Topology::extract($root, $files,
            static fn (int $done, int $total, string $file) => $progress->update($done, $total, $file));
        if ($files === []) {
            $progress->skip(1, 'Extracting PHP topology', 'no source files');
        }
        $progress->stage(2, 'Calculating current CRAP');
        $analysis = (new Analyzer())->analyze($root, $files);
        if ($analysis['diagnostics'] !== []) {
            throw new RuntimeException(Reports::json($analysis['diagnostics']));
        }
        $diagnostics = $graph['diagnostics'] ?? [];
        $coverage = [];
        if ($config['crapper']['enabled']) {
            $path = $root . '/' . $config['crapper']['coverage']['path'];
            if (!is_file($path) || !is_readable($path)) {
                $diagnostics[] = 'Clover coverage unavailable: ' . $config['crapper']['coverage']['path'];
            } else {
                try {
                    $coverage = Clover::read($path, $root);
                } catch (\InvalidArgumentException $error) {
                    $diagnostics[] = 'Clover coverage unavailable: ' . $error->getMessage();
                }
            }
        }
        $entries = $config['crapper']['enabled'] ? Metrics::crap($analysis['units'], $coverage, $config['crapper']['threshold']) : [];
        $progress->update(1, 1);
        $progress->stage(3, 'Joining mutation snapshot');
        [$overlay, $mutants] = self::overlay($root, $config, $files);
        $unknown = 0;
        $mutationUnknown = 0;
        $violations = 0;
        $entriesByClass = [];
        $entriesByMember = [];
        foreach ($entries as $entry) {
            $entriesByClass[$entry['namespace']][] = $entry;
            $entriesByMember[$entry['namespace'] . "\0" . $entry['file'] . "\0" . $entry['name'] . "\0" . $entry['start_line']][] = $entry;
        }
        [$mutantsByClass, $mutantsByMember, $bindingDiagnostics] = self::mutationBindings($graph['nodes'], $analysis['units'], $mutants);
        array_push($diagnostics, ...$bindingDiagnostics);
        foreach ($graph['nodes'] as &$node) {
            $ambiguous = $node['kind'] === 'ambiguous';
            $classEntries = $ambiguous ? [] : ($entriesByClass[$node['id']] ?? []);
            $classMutants = $ambiguous ? [] : ($mutantsByClass[$node['id']] ?? []);
            $failed = count(array_filter($classEntries, static fn (array $entry): bool => $entry['exceeded_threshold']));
            $unmeasured = count(array_filter($classEntries, static fn (array $entry): bool => $entry['crap'] === null));
            foreach ($node['members'] as &$member) {
                $key = $node['id'] . "\0" . $node['file'] . "\0" . $member['name'] . "\0" . $member['line'];
                $matching = $member['kind'] === 'method' ? ($entriesByMember[$key] ?? []) : [];
                $member['metrics'] = $matching === [] ? null : array_intersect_key($matching[0], array_flip(['id', 'complexity', 'coverage', 'crap', 'exceeded_threshold']));
                $member['mutations'] = $member['kind'] === 'method' ? ($mutantsByMember[$key] ?? []) : [];
            }
            unset($member);
            $killed = count(array_filter($classMutants, static fn (array $mutant): bool => $mutant['status'] === 'killed'));
            $survivors = count(array_filter($classMutants, static fn (array $mutant): bool => $mutant['status'] === 'survived'));
            $incomplete = count(array_filter($classMutants, static fn (array $mutant): bool => !in_array($mutant['status'], ['killed', 'ignored'], true)));
            $node['metrics'] = [
                'crap' => $classEntries, 'crap_status' => $failed > 0 ? 'FAIL'
                    : (($classEntries === [] || $unmeasured > 0) ? 'unknown' : 'PASS'),
                'mutation_status' => $ambiguous ? 'unknown' : ($overlay['status'] !== 'available' ? $overlay['status']
                    : ($survivors > 0 ? 'survived' : ($incomplete > 0 ? 'incomplete' : ($killed > 0 ? 'killed' : 'unknown')))),
                'survived' => $survivors, 'mutants' => count($classMutants),
            ];
            if (!$node['external']) {
                $unknown += (int) ($node['metrics']['crap_status'] === 'unknown');
                $mutationUnknown += (int) ($node['metrics']['mutation_status'] === 'unknown');
                $violations += $failed;
            }
        }
        unset($node);
        $progress->update(1, 1);
        $namespace = trim($options['namespace'] ?? '', '\\');
        $visible = Viewer::visible($graph['nodes'], $namespace, $config['uml']['view_limit']);
        $mutationWarnings = count(array_filter($mutants, static fn (array $mutant): bool => !in_array($mutant['status'], ['killed', 'ignored'], true)));
        $status = $diagnostics !== [] || $mutationWarnings > 0 || $mutationUnknown > 0 || $unknown > 0 || $violations > 0 || $overlay['status'] !== 'available' || $visible === [] ? 'WARN' : 'PASS';
        $report = [
            'schema_version' => '1.0', 'tool' => 'uml', 'status' => $status,
            'scope' => ['files' => $files, 'namespace' => $namespace, 'view_limit' => $config['uml']['view_limit']],
            'nodes' => $graph['nodes'], 'edges' => $graph['edges'], 'mutation_overlay' => $overlay,
            'limits' => ['anonymous_classes_omitted' => $graph['anonymous_classes_omitted'],
                'dynamic_dependencies' => 'not inferred', 'docblock_types' => 'not inferred'],
            'diagnostics' => [...$diagnostics, ...($visible === [] ? ['No classes in the selected namespace/source scope.'] : [])],
        ];
        if (isset($graph['ambiguous_edges_omitted'])) {
            $report['limits']['ambiguous_edges_omitted'] = $graph['ambiguous_edges_omitted'];
        }
        $progress->stage(4, 'Saving UML reports');
        $json = Reports::json($report);
        $paths = Reports::bundle($root, $config['reports']['directory'], 'uml', [
            'uml.json' => $json, 'uml.svg' => Viewer::svg($report), 'uml.html' => Viewer::html($report),
        ], $config['reports']['keep_generations']);
        $progress->update(1, 1);
        $progress->complete();
        $findings = $options['verbose'] ? array_map(static fn (array $node): string => $node['id'] . ' | ' . $node['kind'] . ' | ' . ($node['kind'] === 'ambiguous'
            ? implode(', ', array_map(static fn (array $definition): string => $definition['file'] . ':' . $definition['start_line'], $node['definitions']))
            : ($node['file'] ?? 'external')), $graph['nodes']) : [];
        array_push($findings, ...$diagnostics);
        echo $options['format'] === 'json' ? $json : TextReport::tool('uml', $status, 0, [
            'Topology: ' . count($graph['nodes']) . ' classes/types | ' . count($graph['edges']) . ' relations',
            'Initial view: ' . count($visible) . ' nodes | limit ' . $config['uml']['view_limit'] . ' (JSON is complete)',
            'CRAP: ' . $violations . ' violations | ' . $unknown . ' classes with unknown metrics',
            'Mutation: ' . $mutationUnknown . ' classes with unknown metrics',
            'Mutation overlay: ' . $overlay['status'] . ' | ' . $overlay['reason'],
        ], $findings, $paths, ['Open ' . $config['reports']['directory'] . '/uml.html in a browser; it works offline.',
            'Use search, namespace filters and class details; dynamic dependencies are not inferred.'], $options['verbose']);
        return 0;
    }

    private static function mutationBindings(array $nodes, array $units, array $mutants): array
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

    private static function overlay(string $root, array $config, array $files, ?callable $fingerprints = null): array
    {
        $path = $root . '/' . $config['reports']['directory'] . '/mutate.json';
        if (!file_exists($path)) {
            return [['status' => 'unavailable', 'reason' => 'no mutation snapshot; tests were not run'], []];
        }
        try {
            $report = Reports::readBundle($root, $config['reports']['directory'], 'mutate', 'mutate.json');
        } catch (\InvalidArgumentException | \JsonException $error) {
            return [['status' => 'unavailable', 'reason' => $error->getMessage()], []];
        }
        if (($report['tool'] ?? null) !== 'mutate' || ($report['schema_version'] ?? null) !== '1.0'
            || ($report['engine']['version'] ?? null) !== InfectionRunner::VERSION
            || !in_array($report['status'] ?? null, ['PASS', 'WARN', 'FAIL'], true)
            || ($report['scope']['files'] ?? null) !== $files) {
            return [['status' => 'stale', 'reason' => 'mutation scope or format no longer match'], []];
        }
        if (!is_array($report['mutants'] ?? null) || !array_is_list($report['mutants'])) {
            return [['status' => 'unavailable', 'reason' => 'invalid snapshot mutants'], []];
        }
        $selected = array_fill_keys($files, true);
        foreach ($report['mutants'] as $mutant) {
            if (!is_array($mutant) || !array_key_exists('unit_id', $mutant)
                || ($mutant['unit_id'] !== null && !is_string($mutant['unit_id']))
                || !is_string($mutant['file'] ?? null) || !isset($selected[$mutant['file']])
                || !is_int($mutant['line'] ?? null) || $mutant['line'] < 1
                || !in_array($mutant['status'] ?? null, ['killed', 'survived', 'uncovered', 'timeout', 'error', 'syntax_error', 'ignored'], true)) {
                return [['status' => 'unavailable', 'reason' => 'invalid snapshot mutant'], []];
            }
        }
        if (!is_array($report['fingerprints'] ?? null)) {
            return [['status' => 'unavailable', 'reason' => 'invalid snapshot fingerprints'], []];
        }
        // Errors in our own computation must not be disguised as stale user data.
        $current = ($fingerprints ?? InfectionReport::fingerprints(...))($root, $config);
        if (Reports::json($report['fingerprints'] ?? null) !== Reports::json($current)) {
            return [['status' => 'stale', 'reason' => 'source/tests/configuration no longer match'], []];
        }
        return [['status' => 'available', 'reason' => 'verified current mutation snapshot'], $report['mutants']];
    }
}
