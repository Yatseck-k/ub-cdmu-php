<?php

declare(strict_types=1);

namespace UbModePhp;

final class TextReport
{
    public static function render(array $context): string
    {
        [
            'root' => $root, 'command' => $command, 'changed' => $changed,
            'files' => $files, 'unitCount' => $unitCount, 'entries' => $entries,
            'candidates' => $candidates, 'runCrap' => $runCrap, 'runDry' => $runDry,
            'crapThreshold' => $crapThreshold, 'dryThreshold' => $dryThreshold,
            'verbose' => $verbose, 'paths' => $paths, 'status' => $status, 'exitCode' => $exitCode,
        ] = $context;
        $style = new TerminalStyle(STDOUT);
        $actions = [];
        $lines = [
            '',
            $style->paint('CDMU | ' . $command, '1;36'),
            'Project: ' . $root . ($changed ? ' | changed PHP files' : ' | full scan'),
        ];
        self::section($lines, 'CRAP / complexity and coverage', $style);
        $failed = array_values(array_filter($entries, static fn (array $entry): bool => $entry['exceeded_threshold']));
        $unknown = array_values(array_filter($entries, static fn (array $entry): bool => $entry['coverage'] === null));
        $byLocation = static fn (array $a, array $b): int =>
            [$a['file'], $a['start_line'], $a['id']] <=> [$b['file'], $b['start_line'], $b['id']];
        usort($failed, static fn (array $a, array $b): int =>
            ($b['crap'] <=> $a['crap']) ?: $byLocation($a, $b));
        usort($unknown, static fn (array $a, array $b): int =>
            ($b['complexity'] <=> $a['complexity']) ?: $byLocation($a, $b));
        if ($runCrap) {
            $lines[] = sprintf('CRAP: %d entries | threshold >= %g', count($entries), $crapThreshold);
            if ($verbose) {
                foreach ($entries as $entry) {
                    $lines[] = self::entry($entry, $style);
                }
            } else {
                foreach (array_slice($failed, 0, 10) as $entry) {
                    $lines[] = self::entry($entry, $style);
                }
                self::hidden($lines, count($failed) - 10, 'CRAP violations');
                foreach (array_slice($unknown, 0, 5) as $entry) {
                    $lines[] = self::entry($entry, $style);
                }
                self::hidden($lines, count($unknown) - 5, 'unknown-coverage entries');
                $passed = count($entries) - count($failed) - count($unknown);
                if ($passed > 0) {
                    $lines[] = $passed . ' known entries below threshold (use --verbose for all entries).';
                }
            }
        } else {
            $lines[] = 'CRAP: skipped (disabled or not selected)';
        }

        $groups = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['left']['id'] . ':' . sprintf('%.3f', $candidate['score']);
            $groups[$key]['left'] = $candidate['left'];
            $groups[$key]['score'] = $candidate['score'];
            $groups[$key]['matches'][] = $candidate['right'];
        }
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int =>
            ($b['score'] <=> $a['score']) ?: $byLocation($a['left'], $b['left']));
        self::section($lines, 'DRY / duplicate candidates', $style);
        $lines[] = $runDry
            ? sprintf(
                'DRY: %d pairs in %d groups | threshold >= %.2f (warnings)',
                count($candidates),
                count($groups),
                $dryThreshold,
            )
            : 'DRY: skipped (disabled or not selected)';
        foreach ($verbose ? $groups : array_slice($groups, 0, 5) as $group) {
            $left = $group['left'];
            $lines[] = $style->paint(sprintf(
                'DRY %.3f %s:%d-%d %s (%d matches)',
                $group['score'],
                $left['file'],
                $left['start_line'],
                $left['end_line'],
                $left['name'],
                count($group['matches']),
            ), '33');
            foreach ($verbose ? $group['matches'] : array_slice($group['matches'], 0, 3) as $match) {
                $lines[] = sprintf(
                    '  -> %s:%d-%d %s',
                    $match['file'],
                    $match['start_line'],
                    $match['end_line'],
                    $match['name'],
                );
            }
            if (!$verbose) {
                self::hidden($lines, count($group['matches']) - 3, 'matches in this group');
            }
        }
        if (!$verbose) {
            self::hidden($lines, count($groups) - 5, 'DRY groups');
        }

        $statusColor = match ($status) {
            'PASS' => '1;32',
            'FAIL' => '1;31',
            default => '1;33',
        };
        self::section($lines, 'Result: ' . $status . ' (exit ' . $exitCode . ')', $style, $statusColor);
        $lines[] = sprintf('Scope: %d PHP files | %d analyzed units', count($files), $unitCount);
        if ($files === [] && $changed) {
            $lines[] = 'No changed PHP files; this does not assess the whole project.';
            $actions[] = 'Omit --changed when a full project scan is needed.';
        }
        if (!$runCrap && !$runDry) {
            $lines[] = 'No checks enabled; quality was not assessed.';
            $actions[] = 'Enable the required checks in .ub-mode.yaml.';
        }
        $lines[] = $runCrap
            ? sprintf(
                'CRAP: %d violations | coverage: %d known, %d unknown',
                count($failed),
                count($entries) - count($unknown),
                count($unknown),
            )
            : 'CRAP: not assessed';
        $lines[] = $runDry ? sprintf('DRY: %d pairs | %d groups', count($candidates), count($groups)) : 'DRY: not assessed';
        self::section(
            $lines,
            $paths === [] ? 'Reports: none (no applicable report formats enabled)' : 'Reports:',
            $style,
        );
        foreach ($paths as $path) {
            $lines[] = '  - ' . $style->paint($path, '36');
        }
        if ($unknown !== []) {
            $actions[] = 'Generate Clover coverage with project tests, then rerun CDMU; unknown is not 0%.';
        }
        if ($failed !== []) {
            $actions[] = 'Simplify flagged functions or improve tests; keep the configured CRAP threshold.';
            if ($exitCode === 0) {
                $lines[] = 'CRAP violations do not fail this run because quality.fail_on disables that gate.';
            }
        }
        if ($candidates !== []) {
            $actions[] = 'Review duplicate candidates for shared meaning before extracting common code.';
        }
        if ($status === 'PASS') {
            $lines[] = 'No findings in the checked scope.';
        }
        if ($actions !== []) {
            self::section($lines, 'Next steps', $style);
            foreach ($actions as $index => $action) {
                $lines[] = ($index + 1) . '. ' . $action;
            }
        }
        return implode("\n", $lines) . "\n\n";
    }

    public static function tool(
        string $tool,
        string $status,
        int $code,
        array $summary,
        array $findings,
        array $paths,
        array $actions,
        bool $verbose,
    ): string {
        $style = new TerminalStyle(STDOUT);
        $lines = ['', $style->paint('CDMU | ' . $tool, '1;36')];
        self::section($lines, 'Result: ' . $status . ' (exit ' . $code . ')', $style,
            $status === 'FAIL' ? '1;31' : ($status === 'PASS' ? '1;32' : '1;33'));
        array_push($lines, ...$summary);
        if ($findings !== []) {
            self::section($lines, 'Findings', $style);
            foreach ($verbose ? $findings : array_slice($findings, 0, 10) as $finding) {
                $lines[] = $style->paint($finding, $status === 'FAIL' ? '31' : '33');
            }
            if (!$verbose) {
                self::hidden($lines, count($findings) - 10, 'findings');
            }
        }
        self::section($lines, 'Reports:', $style);
        foreach ($paths as $path) {
            $lines[] = '  - ' . $style->paint($path, '36');
        }
        self::section($lines, 'Next steps', $style);
        foreach ($actions as $index => $action) {
            $lines[] = ($index + 1) . '. ' . $action;
        }
        return implode("\n", $lines) . "\n\n";
    }

    private static function entry(array $entry, TerminalStyle $style): string
    {
        $line = sprintf(
            'CRAP %s CC=%d %s:%d %s%s',
            $entry['crap'] === null ? 'unknown' : sprintf('%.3f', $entry['crap']),
            $entry['complexity'],
            $entry['file'],
            $entry['start_line'],
            $entry['name'],
            $entry['exceeded_threshold'] ? ' [violation]' : '',
        );
        $code = $entry['exceeded_threshold'] ? '31' : ($entry['crap'] === null ? '33' : '32');
        return $style->paint($line, $code);
    }

    private static function section(
        array &$lines,
        string $title,
        TerminalStyle $style,
        string $code = '1;36',
    ): void
    {
        $lines[] = '';
        $lines[] = $style->paint(str_repeat('-', 64), '2');
        $lines[] = $style->paint($title, $code);
    }

    private static function hidden(array &$lines, int $count, string $label): void
    {
        if ($count > 0) {
            $lines[] = sprintf('  ... %d %s hidden; use --verbose or the full JSON report if enabled.', $count, $label);
        }
    }
}
