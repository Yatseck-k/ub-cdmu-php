<?php

declare(strict_types=1);

namespace UbModePhp;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UbModePhp\Analyzer\Analyzer;

final class Cli
{
    private const VERSION = '1.0';

    public function run(array $arguments): int
    {
        try {
            [$root, $configPath, $command, $format, $changed, $threshold] = $this->parse($arguments);
        } catch (InvalidArgumentException $error) {
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            return 4;
        }
        if ($command === 'doctor') {
            return $this->doctor($root, $format);
        }
        try {
            $config = Config::load($root, $configPath);
            $files = SourceFiles::find($root, $config, $changed);
            if (!$changed && $files === []) {
                throw new InvalidArgumentException('no PHP files found in configured source directories');
            }
            $showProgress = $format === 'text' && $files !== []
                && function_exists('stream_isatty') && stream_isatty(STDERR);
            $progressWidth = 0;
            $progress = null;
            if ($showProgress) {
                $this->writeProgress(0, count($files), 'starting', $progressWidth);
                $progress = function (int $completed, int $total, string $file) use (&$progressWidth): void {
                    $this->writeProgress($completed, $total, $file, $progressWidth);
                };
            }
            try {
                $analysis = (new Analyzer())->analyze($root, $files, $progress);
            } finally {
                if ($showProgress) {
                    fwrite(STDERR, "\n");
                }
            }
            if ($analysis['diagnostics'] !== []) {
                $messages = array_map(
                    static fn (array $item): string => $item['file'] . ': ' . $item['message'],
                    $analysis['diagnostics'],
                );
                throw new RuntimeException(implode('; ', $messages));
            }
            $runCrap = in_array($command, ['check', 'crap'], true) && $config['crapper']['enabled'];
            $runDry = in_array($command, ['check', 'dry'], true) && $config['dryer']['enabled'];
            $crapThreshold = (float) ($command === 'crap' && $threshold !== null
                ? $threshold : $config['crapper']['threshold']);
            $dryThreshold = (float) ($command === 'dry' && $threshold !== null
                ? $threshold : $config['dryer']['threshold']);
            if ($runDry && $dryThreshold > 1) {
                throw new InvalidArgumentException('Dryer threshold must be between 0 and 1');
            }
            $coverage = [];
            if ($runCrap) {
                $coveragePath = $root . '/' . $config['crapper']['coverage']['path'];
                if (file_exists($coveragePath)) {
                    $coverage = Clover::read($coveragePath, $root);
                }
            }
            $entries = $runCrap ? Metrics::crap($analysis['units'], $coverage, $crapThreshold) : [];
            $candidates = $runDry
                ? Metrics::dry(
                    $analysis['units'],
                    $dryThreshold,
                    $config['dryer']['min_lines'],
                    $config['dryer']['min_nodes'],
                ) : [];
            $report = [
                'schema_version' => self::VERSION,
                'tool' => $command,
                'entries' => $entries,
                'candidates' => $candidates,
                'diagnostics' => [],
            ];
            $serialized = Reports::json($report);
            $directory = $root . '/' . $config['reports']['directory'];
            if (in_array('json', $config['reports']['formats'], true)) {
                Reports::atomic($directory . '/' . $command . '.json', $serialized);
            }
            if (in_array('edn', $config['reports']['formats'], true)) {
                if ($runCrap) {
                    Reports::atomic($directory . '/crap.edn', Reports::crapEdn($entries));
                }
                if ($runDry) {
                    Reports::atomic($directory . '/dry.edn', Reports::dryEdn($candidates));
                }
            }
            $this->output(
                $format,
                $serialized,
                $files,
                $entries,
                $candidates,
                $runCrap,
                $runDry,
                $crapThreshold,
                $dryThreshold,
            );
            $hasFailure = count(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['exceeded_threshold'],
            )) > 0;
            return in_array('exceeded_crap_threshold', $config['quality']['fail_on'], true) && $hasFailure ? 2 : 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            return 3;
        }
    }

    private function parse(array $arguments): array
    {
        $root = getcwd();
        $config = null;
        $command = null;
        $format = 'text';
        $changed = false;
        $threshold = null;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($command === null && in_array($argument, ['--root', '--config'], true)) {
                $value = $arguments[++$index] ?? null;
                if ($value === null || $value === '') {
                    throw new InvalidArgumentException($argument . ' needs a value');
                }
                if ($argument === '--root') {
                    $root = $value;
                } else {
                    $config = $value;
                }
            } elseif ($command === null && in_array($argument, ['doctor', 'check', 'crap', 'dry'], true)) {
                $command = $argument;
            } elseif ($command !== null && $argument === '--format') {
                $format = $arguments[++$index] ?? '';
                if (!in_array($format, ['text', 'json'], true)) {
                    throw new InvalidArgumentException('--format must be text or json');
                }
            } elseif ($command !== null && $command !== 'doctor' && $argument === '--changed') {
                $changed = true;
            } elseif ($command !== null && $command !== 'doctor' && $argument === '--threshold') {
                $value = $arguments[++$index] ?? null;
                if (
                    $command === 'check' || $value === null || !is_numeric($value)
                    || !is_finite((float) $value) || (float) $value <= 0
                ) {
                    throw new InvalidArgumentException('--threshold applies to crap or dry and must be positive');
                }
                $threshold = (float) $value;
            } else {
                throw new InvalidArgumentException('invalid command or option: ' . $argument);
            }
        }
        if ($command === null) {
            throw new InvalidArgumentException('missing command');
        }
        return [$root, $config, $command, $format, $changed, $threshold];
    }

    private function doctor(string $root, string $format): int
    {
        $checks = [
            'php_version_id' => PHP_VERSION_ID,
            'php_supported' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'analyzer' => class_exists(Analyzer::class),
            'composer_dependencies' => class_exists(\PhpParser\ParserFactory::class)
                && class_exists(\Symfony\Component\Yaml\Yaml::class),
            'project' => is_dir($root),
        ];
        if ($format === 'json') {
            echo Reports::json(['schema_version' => self::VERSION, 'checks' => $checks]);
        } else {
            foreach ($checks as $key => $value) {
                echo $key . ': ' . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . "\n";
            }
        }
        return $checks['php_supported'] && $checks['analyzer']
            && $checks['composer_dependencies'] && $checks['project'] ? 0 : 3;
    }

    private function output(
        string $format,
        string $json,
        array $files,
        array $entries,
        array $candidates,
        bool $runCrap,
        bool $runDry,
        float $crapThreshold,
        float $dryThreshold,
    ): void {
        if ($format === 'json') {
            echo $json;
            return;
        }

        if ($runCrap) {
            echo 'CRAP (' . count($entries) . " entries)\n";
        }
        foreach ($entries as $entry) {
            $value = $entry['crap'] === null ? 'unknown' : sprintf('%.3f', $entry['crap']);
            echo 'CRAP ' . $value . ' CC=' . $entry['complexity'] . ' '
                . $entry['file'] . ':' . $entry['start_line'] . ' ' . $entry['name'] . "\n";
        }

        $groups = [];
        foreach ($candidates as $candidate) {
            $left = $candidate['left'];
            $key = $left['id'] . ':' . sprintf('%.3f', $candidate['score']);
            $groups[$key]['score'] = $candidate['score'];
            $groups[$key]['left'] = $left;
            $groups[$key]['matches'][] = $candidate['right'];
        }
        if ($runDry) {
            echo 'DRY (' . count($candidates) . ' pairs)\n';
        }
        foreach ($groups as $group) {
            $left = $group['left'];
            echo 'DRY ' . sprintf('%.3f', $group['score']) . ' '
                . $left['file'] . ':' . $left['start_line'] . '-' . $left['end_line']
                . ' (' . count($group['matches']) . " matches)\n";
            foreach ($group['matches'] as $match) {
                echo '  -> ' . $match['file'] . ':' . $match['start_line'] . '-' . $match['end_line'] . "\n";
            }
        }

        $unknown = count(array_filter($entries, static fn (array $entry): bool => $entry['coverage'] === null));
        $failed = count(array_filter($entries, static fn (array $entry): bool => $entry['exceeded_threshold']));
        $parts = [count($files) . ' PHP files'];
        $parts[] = $runCrap
            ? sprintf(
                'CRAP %d entries (%d unknown, %d >= %.1f)',
                count($entries),
                $unknown,
                $failed,
                $crapThreshold,
            )
            : 'CRAP skipped';
        $parts[] = $runDry
            ? sprintf('DRY %d pairs in %d groups (threshold %.2f)', count($candidates), count($groups), $dryThreshold)
            : 'DRY skipped';
        echo 'Summary: ' . implode(' | ', $parts) . "\n";
    }

    private function writeProgress(int $completed, int $total, string $file, int &$lastWidth): void
    {
        $barWidth = 24;
        $percent = $total === 0 ? 100 : (int) floor($completed * 100 / $total);
        $filled = (int) floor($percent * $barWidth / 100);
        if (strlen($file) > 60) {
            $file = '...' . substr($file, -57);
        }
        $line = sprintf(
            "Analyzing [%s%s] %3d%% %d/%d %s",
            str_repeat('#', $filled),
            str_repeat('-', $barWidth - $filled),
            $percent,
            $completed,
            $total,
            $file,
        );
        fwrite(STDERR, "\r" . str_pad($line, $lastWidth));
        $lastWidth = max($lastWidth, strlen($line));
    }
}
