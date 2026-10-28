<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

use RuntimeException;
use UbModePhp\Analyzer\Analyzer;
use UbModePhp\Config;
use UbModePhp\Progress;
use UbModePhp\Reports;
use UbModePhp\ReportRetention;
use UbModePhp\SourceFiles;
use UbModePhp\TextReport;

final class MutationCommand
{
    public static function run(string $root, array $config, array $options, Progress $progress): int
    {
        $progress->stage(1, 'Preparing mutation run');
        $files = SourceFiles::find($root, $config, $options['changed']);
        if (!$options['changed'] && $files === []) {
            throw new RuntimeException('no PHP files found in configured source directories');
        }
        $analysis = (new Analyzer())->analyze($root, $files);
        if ($analysis['diagnostics'] !== []) {
            throw new RuntimeException(Reports::json($analysis['diagnostics']));
        }
        $fingerprints = InfectionReport::fingerprints($root, $config);
        $progress->update(1, 1);
        $raw = "null\n";
        $log = "No changed PHP files; Infection was not run.\n";
        $version = null;
        $summary = array_fill_keys(['total', 'skipped', 'killed', 'survived', 'uncovered', 'timeout', 'error', 'syntax_error', 'ignored'], 0);
        $summary += ['msi' => null, 'covered_msi' => null, 'mutation_coverage' => null];
        $mutants = [];
        $run = null;
        $published = false;
        try {
            if ($files !== []) {
                $engine = InfectionRunner::run($root, $config, $files, $progress);
                ['raw' => $raw, 'log' => $log, 'version' => $version, 'run' => $run] = $engine;
                $progress->stage(3, 'Reading mutation result');
                ['summary' => $summary, 'mutants' => $mutants] = InfectionReport::normalize($raw, $root, $analysis['units'], $files);
                $currentConfig = Config::load($root, $options['config_path'] ?? null);
                if ($fingerprints !== InfectionReport::fingerprints($root, $currentConfig)) {
                    throw new RuntimeException('source, tests or configuration changed during mutation run; result not published');
                }
                $progress->update(1, 1);
            } else {
                $progress->skip(2, 'Running Infection', 'no changed PHP files');
                $progress->skip(3, 'Reading mutation result', 'engine not run');
            }
            $minimum = $options['min_msi'] ?? $config['mutator']['min_msi'];
            $unevaluated = $minimum !== null && $summary['msi'] === null;
            $code = $unevaluated ? 3 : ($minimum !== null && $summary['msi'] < $minimum ? 2 : 0);
            $warning = $summary['msi'] === null || $summary['survived'] + $summary['uncovered']
                + $summary['timeout'] + $summary['error'] + $summary['syntax_error'] > 0;
            $status = $code !== 0 ? 'FAIL' : ($warning ? 'WARN' : 'PASS');
            $report = [
                'schema_version' => '1.0', 'tool' => 'mutate', 'status' => $status, 'exit_code' => $code,
                'engine' => ['name' => 'infection', 'version' => $version],
                'scope' => ['changed' => $options['changed'], 'files' => $files], 'fingerprints' => $fingerprints,
                'summary' => $summary + ['min_msi' => $minimum, 'gate_evaluated' => $minimum !== null && $summary['msi'] !== null],
                'mutants' => $mutants, 'diagnostics' => $files === [] ? ['No changed PHP files; engine not run.'] : [],
            ];
            if ($unevaluated) {
                $run ??= ReportRetention::startRun($root, $config);
                $report['diagnostics'][] = 'MSI gate not evaluated: no measurable mutation score.';
                $json = Reports::json($report);
                $path = $run['directory'] . '/failure.json';
                Reports::atomic($path, $json);
                $progress->fail();
                $paths = [$path];
                if (is_file($run['directory'] . '/engine.log')) {
                    $paths[] = $run['directory'] . '/engine.log';
                }
                echo $options['format'] === 'json' ? $json : TextReport::tool('mutate', 'FAIL', 3, [
                    'MSI: unknown | required minimum: ' . $minimum,
                    'Scope: ' . count($files) . ' PHP files | previous measurement was not replaced',
                ], $report['diagnostics'], $paths, [
                    'Check selected source, mutators and test coverage, then rerun the required MSI gate.',
                ], $options['verbose']);
                return 3;
            }
            $progress->stage(4, 'Saving mutation reports');
            $json = Reports::json($report);
            $paths = Reports::bundle($root, $config['reports']['directory'], 'mutate', [
                'mutate.json' => $json, 'mutate.raw.json' => $raw, 'mutate.log' => $log,
            ], $config['reports']['keep_generations']);
            $published = true;
            $progress->update(1, 1);
            $progress->complete();
            $findings = [];
            foreach ($mutants as $mutant) {
                if ($options['verbose'] || !in_array($mutant['status'], ['killed', 'ignored'], true)) {
                    $findings[] = sprintf('%s %s:%d %s', strtoupper($mutant['status']), $mutant['file'], $mutant['line'], $mutant['mutator']);
                }
            }
            $msi = $summary['msi'] === null ? 'unknown (no mutants)' : sprintf('%.2f%%', $summary['msi']);
            $actions = $warning || $code === 2
                ? ['Review survivors and uncovered lines; timeout and error mutants are separate from killed mutants.']
                : ['No mutation findings in the checked scope.'];
            echo $options['format'] === 'json' ? $json : TextReport::tool('mutate', $status, $code, [
                'Scope: ' . count($files) . ' PHP files | Infection ' . ($version ?? 'not run'),
                'MSI: ' . $msi . ' | covered MSI: ' . ($summary['covered_msi'] === null ? 'unknown' : sprintf('%.2f%%', $summary['covered_msi'])),
                sprintf('Mutants: %d total | %d killed | %d survived | %d uncovered | %d timeout | %d errors',
                    $summary['total'], $summary['killed'], $summary['survived'], $summary['uncovered'], $summary['timeout'],
                    $summary['error'] + $summary['syntax_error']),
                sprintf('Other: %d skipped | %d ignored | %d syntax errors', $summary['skipped'], $summary['ignored'], $summary['syntax_error']),
                'MSI threshold: ' . ($minimum ?? 'none'),
            ], $findings, $paths, $actions, $options['verbose']);
            return $code;
        } finally {
            if ($run !== null) {
                ReportRetention::finishRun($root, $config, $run, $published);
            }
        }
    }
}
