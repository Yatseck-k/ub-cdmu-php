<?php

declare(strict_types=1);

namespace UbModePhp;

use InvalidArgumentException;
use RuntimeException;
use UbModePhp\Analyzer\Analyzer;

final class CheckCommand
{
    public static function run(string $root, array $config, Options $options, Progress $progress): int
    {
        $command = $options->command;
        $files = SourceFiles::find($root, $config, $options->changed);
        if (!$options->changed && $files === []) {
            throw new InvalidArgumentException('no PHP files found in configured source directories');
        }
        $runCrap = in_array($command, ['check', 'crap'], true) && $config['crapper']['enabled'];
        $runDry = in_array($command, ['check', 'dry'], true) && $config['dryer']['enabled'];
        $crapThreshold = (float) ($command === 'crap' && $options->threshold !== null
            ? $options->threshold : $config['crapper']['threshold']);
        $dryThreshold = (float) ($command === 'dry' && $options->threshold !== null
            ? $options->threshold : $config['dryer']['threshold']);
        if ($runDry && $dryThreshold > 1) {
            throw new InvalidArgumentException('Dryer threshold must be between 0 and 1');
        }
        $units = self::parse($root, $files, $progress);
        $entries = $runCrap ? self::crap($root, $config, $units, $crapThreshold, $progress) : [];
        if (!$runCrap) {
            $progress->skip(2, 'Calculating CRAP', 'disabled or not selected');
        }
        $candidates = self::dry($config, $units, $runDry, $dryThreshold, $progress);
        $serialized = Reports::json([
            'schema_version' => Reports::SCHEMA_VERSION,
            'tool' => $command,
            'entries' => $entries,
            'candidates' => $candidates,
            'diagnostics' => [],
        ]);
        $paths = self::save(
            self::reports($root . '/' . $config['reports']['directory'], $config, $command, $serialized, $runCrap ? $entries : null, $runDry ? $candidates : null),
            $progress,
        );
        $progress->complete();
        $hasFailure = array_filter($entries, static fn (array $entry): bool => $entry['exceeded_threshold']) !== [];
        $unknown = array_filter($entries, static fn (array $entry): bool => $entry['coverage'] === null) !== [];
        $exitCode = in_array('exceeded_crap_threshold', $config['quality']['fail_on'], true) && $hasFailure ? 2 : 0;
        $hasWarning = $unknown || $hasFailure || $candidates !== [] || $files === [] || (!$runCrap && !$runDry);
        $status = $exitCode === 2 ? 'FAIL' : ($hasWarning ? 'WARN' : 'PASS');
        $unitCount = count($units);
        echo $options->format === 'json' ? $serialized : TextReport::render(compact(
            'root',
            'command',
            'files',
            'unitCount',
            'entries',
            'candidates',
            'runCrap',
            'runDry',
            'crapThreshold',
            'dryThreshold',
            'paths',
            'status',
            'exitCode',
        ) + ['changed' => $options->changed, 'verbose' => $options->verbose]);
        return $exitCode;
    }

    /**
     * @param string $root
     * @param list<string> $files
     * @param Progress $progress
     * @return list<array<string, mixed>> analysis units
     */
    private static function parse(string $root, array $files, Progress $progress): array
    {
        if ($files === []) {
            $progress->skip(1, 'Parsing PHP', 'no changed PHP files');
        } else {
            $progress->stage(1, 'Parsing PHP', count($files));
        }
        $analysis = (new Analyzer())->analyze(
            $root,
            $files,
            static fn (int $done, int $total, string $file) => $progress->update($done, $total, $file),
        );
        if ($analysis['diagnostics'] !== []) {
            $messages = array_map(
                static fn (array $item): string => $item['file'] . ': ' . $item['message'],
                $analysis['diagnostics'],
            );
            throw new RuntimeException(implode('; ', $messages));
        }
        return $analysis['units'];
    }

    private static function crap(string $root, array $config, array $units, float $threshold, Progress $progress): array
    {
        if ($units === []) {
            $progress->skip(2, 'Calculating CRAP', 'no units');
        } else {
            $progress->stage(2, 'Calculating CRAP');
        }
        $coverage = [];
        $coveragePath = $root . '/' . $config['crapper']['coverage']['path'];
        if (file_exists($coveragePath)) {
            $coverage = Clover::read($coveragePath, $root);
        }
        $entries = Metrics::crap($units, $coverage, $threshold);
        if ($units !== []) {
            $progress->update(1, 1);
        }
        return $entries;
    }

    private static function dry(array $config, array $units, bool $enabled, float $threshold, Progress $progress): array
    {
        if (!$enabled || $units === []) {
            $progress->skip(3, 'Comparing duplicates', $enabled ? 'no units' : 'disabled or not selected');
            return [];
        }
        return Metrics::dry(
            $units,
            $threshold,
            $config['dryer']['min_lines'],
            $config['dryer']['min_nodes'],
            static function (int $done, int $total) use ($progress): void {
                if ($total === 0) {
                    $progress->skip(3, 'Comparing duplicates', 'no eligible units');
                } elseif ($done === 0) {
                    $progress->stage(3, 'Comparing duplicates', $total);
                } else {
                    $progress->update($done, $total);
                }
            },
        );
    }

    /**
     * @param string $directory
     * @param array<string, mixed> $config
     * @param string $command
     * @param string $json
     * @param ?array<int, array<string, mixed>> $entries null when CRAP did not run
     * @param ?array<int, array<string, mixed>> $candidates null when DRY did not run
     * @return array<string, string> report path => content
     */
    private static function reports(string $directory, array $config, string $command, string $json, ?array $entries, ?array $candidates): array
    {
        $reports = [];
        if (in_array('json', $config['reports']['formats'], true)) {
            $reports[$directory . '/' . $command . '.json'] = $json;
        }
        if (in_array('edn', $config['reports']['formats'], true)) {
            if ($entries !== null) {
                $reports[$directory . '/crap.edn'] = Reports::crapEdn($entries);
            }
            if ($candidates !== null) {
                $reports[$directory . '/dry.edn'] = Reports::dryEdn($candidates);
            }
        }
        return $reports;
    }

    /**
     * @param array $reports
     * @param Progress $progress
     * @return list<string>
     */
    private static function save(array $reports, Progress $progress): array
    {
        if ($reports === []) {
            $progress->skip(4, 'Saving reports', 'no report formats enabled for selected checks');
            return [];
        }
        $progress->stage(4, 'Saving reports', count($reports));
        $paths = [];
        foreach ($reports as $path => $content) {
            Reports::atomic($path, $content);
            $paths[] = $path;
            $progress->update(count($paths), count($reports), $path);
        }
        return $paths;
    }
}
