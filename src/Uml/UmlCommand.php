<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use InvalidArgumentException;
use RuntimeException;
use UbModePhp\Analyzer\Analyzer;
use UbModePhp\Clover;
use UbModePhp\Metrics;
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
        $graph = Topology::extract(
            $root,
            $files,
            static fn (int $done, int $total, string $file) => $progress->update($done, $total, $file)
        );
        if ($files === []) {
            $progress->skip(1, 'Extracting PHP topology', 'no source files');
        }
        $progress->stage(2, 'Calculating current CRAP');
        $analysis = (new Analyzer())->analyze($root, $files);
        if ($analysis['diagnostics'] !== []) {
            throw new RuntimeException(Reports::json($analysis['diagnostics']));
        }
        $diagnostics = $graph['diagnostics'] ?? [];
        [$entries, $coverageDiagnostics] = self::crap($root, $config, $analysis['units']);
        array_push($diagnostics, ...$coverageDiagnostics);
        $progress->update(1, 1);
        $progress->stage(3, 'Joining mutation snapshot');
        [$overlay, $mutants] = MutationOverlay::load($root, $config, $files);
        [$mutantsByClass, $mutantsByMember, $bindingDiagnostics] = MutationOverlay::bind($graph['nodes'], $analysis['units'], $mutants);
        array_push($diagnostics, ...$bindingDiagnostics);
        $totals = self::annotate($graph['nodes'], $entries, $mutantsByClass, $mutantsByMember, $overlay['status']);
        $progress->update(1, 1);
        $namespace = trim($options['namespace'] ?? '', '\\');
        $visible = Viewer::visible($graph['nodes'], $namespace, $config['uml']['view_limit']);
        $mutationWarnings = self::countStatus($mutants, ['killed', 'ignored'], false);
        $status = $diagnostics !== [] || $mutationWarnings > 0 || $totals['mutation_unknown'] > 0 || $totals['unknown'] > 0
            || $totals['violations'] > 0 || $overlay['status'] !== 'available' || $visible === [] ? 'WARN' : 'PASS';
        $report = [
            'schema_version' => Reports::SCHEMA_VERSION, 'tool' => 'uml', 'status' => $status,
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
        echo $options['format'] === 'json' ? $json : self::text($config, $options, $graph, $visible, $overlay, $totals, $status, $diagnostics, $paths);
        return 0;
    }

    /**
     * @param string $root
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $units
     * @return array{array, list<string>} CRAP entries and coverage diagnostics
     */
    private static function crap(string $root, array $config, array $units): array
    {
        $diagnostics = [];
        $coverage = [];
        if ($config['crapper']['enabled']) {
            $path = $root . '/' . $config['crapper']['coverage']['path'];
            if (!is_file($path) || !is_readable($path)) {
                $diagnostics[] = 'Clover coverage unavailable: ' . $config['crapper']['coverage']['path'];
            } else {
                try {
                    $coverage = Clover::read($path, $root);
                } catch (InvalidArgumentException $error) {
                    $diagnostics[] = 'Clover coverage unavailable: ' . $error->getMessage();
                }
            }
        }
        $entries = $config['crapper']['enabled'] ? Metrics::crap($units, $coverage, $config['crapper']['threshold']) : [];
        return [$entries, $diagnostics];
    }

    /**
     * @param array $nodes
     * @param array<array<string, mixed>> $entries
     * @param array $mutantsByClass
     * @param array $mutantsByMember
     * @param string $overlayStatus
     * @return array{unknown: int, mutation_unknown: int, violations: int} totals over non-external classes
     */
    private static function annotate(array &$nodes, array $entries, array $mutantsByClass, array $mutantsByMember, string $overlayStatus): array
    {
        $entriesByClass = [];
        $entriesByMember = [];
        foreach ($entries as $entry) {
            $entriesByClass[$entry['namespace']][] = $entry;
            $entriesByMember[$entry['namespace'] . "\0" . $entry['file'] . "\0" . $entry['name'] . "\0" . $entry['start_line']][] = $entry;
        }
        $totals = ['unknown' => 0, 'mutation_unknown' => 0, 'violations' => 0];
        foreach ($nodes as &$node) {
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
            $killed = self::countStatus($classMutants, ['killed']);
            $survivors = self::countStatus($classMutants, ['survived']);
            $incomplete = self::countStatus($classMutants, ['killed', 'ignored'], false);
            $node['metrics'] = [
                'crap' => $classEntries, 'crap_status' => $failed > 0 ? 'FAIL'
                    : (($classEntries === [] || $unmeasured > 0) ? 'unknown' : 'PASS'),
                'mutation_status' => $ambiguous ? 'unknown' : ($overlayStatus !== 'available' ? $overlayStatus
                    : ($survivors > 0 ? 'survived' : ($incomplete > 0 ? 'incomplete' : ($killed > 0 ? 'killed' : 'unknown')))),
                'survived' => $survivors, 'mutants' => count($classMutants),
            ];
            if (!$node['external']) {
                $totals['unknown'] += (int) ($node['metrics']['crap_status'] === 'unknown');
                $totals['mutation_unknown'] += (int) ($node['metrics']['mutation_status'] === 'unknown');
                $totals['violations'] += $failed;
            }
        }
        unset($node);
        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $mutants
     * @param list<string> $statuses
     * @param bool $matching
     * @return int
     */
    private static function countStatus(array $mutants, array $statuses, bool $matching = true): int
    {
        return count(array_filter($mutants, static fn (array $mutant): bool => in_array($mutant['status'], $statuses, true) === $matching));
    }

    private static function text(array $config, array $options, array $graph, array $visible, array $overlay, array $totals, string $status, array $diagnostics, array $paths): string
    {
        $findings = $options['verbose'] ? array_map(static fn (array $node): string => $node['id'] . ' | ' . $node['kind'] . ' | ' . ($node['kind'] === 'ambiguous'
            ? implode(', ', array_map(static fn (array $definition): string => $definition['file'] . ':' . $definition['start_line'], $node['definitions']))
            : ($node['file'] ?? 'external')), $graph['nodes']) : [];
        array_push($findings, ...$diagnostics);
        return TextReport::tool('uml', $status, 0, [
            'Topology: ' . count($graph['nodes']) . ' classes/types | ' . count($graph['edges']) . ' relations',
            'Initial view: ' . count($visible) . ' nodes | limit ' . $config['uml']['view_limit'] . ' (JSON is complete)',
            'CRAP: ' . $totals['violations'] . ' violations | ' . $totals['unknown'] . ' classes with unknown metrics',
            'Mutation: ' . $totals['mutation_unknown'] . ' classes with unknown metrics',
            'Mutation overlay: ' . $overlay['status'] . ' | ' . $overlay['reason'],
        ], $findings, $paths, ['Open ' . $config['reports']['directory'] . '/uml.html in a browser; it works offline.',
            'Use search, namespace filters and class details; dynamic dependencies are not inferred.'], $options['verbose']);
    }
}
