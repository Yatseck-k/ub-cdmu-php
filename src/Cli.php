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
            [$root, $configPath, $command, $format, $changed, $threshold, $progressMode, $verbose, $tools, $minimum, $namespace] = $this->parse($arguments);
        } catch (InvalidArgumentException $error) {
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            return 4;
        }
        if ($command === 'doctor') {
            return $this->doctor($root, $format, $tools, $configPath);
        }
        $progress = new Progress($progressMode === 'always' || ($progressMode === 'auto' && $format === 'text'));
        try {
            $canonicalRoot = realpath($root);
            if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
                throw new RuntimeException('root directory does not exist');
            }
            $root = $canonicalRoot;
            $config = Config::load($root, $configPath);
            if (in_array($command, ['mutate', 'uml'], true)) {
                $options = ['changed' => $changed, 'format' => $format, 'verbose' => $verbose,
                    'min_msi' => $minimum, 'namespace' => $namespace,
                    'config_path' => $configPath ?? $root . '/.ub-mode.yaml'];
                return $command === 'mutate'
                    ? Mutation\MutationCommand::run($root, $config, $options, $progress)
                    : Uml\UmlCommand::run($root, $config, $options, $progress);
            }
            $files = SourceFiles::find($root, $config, $changed);
            if (!$changed && $files === []) {
                throw new InvalidArgumentException('no PHP files found in configured source directories');
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
            $entries = [];
            if ($runCrap) {
                if ($analysis['units'] === []) {
                    $progress->skip(2, 'Calculating CRAP', 'no units');
                } else {
                    $progress->stage(2, 'Calculating CRAP');
                }
                $coverage = [];
                $coveragePath = $root . '/' . $config['crapper']['coverage']['path'];
                if (file_exists($coveragePath)) {
                    $coverage = Clover::read($coveragePath, $root);
                }
                $entries = Metrics::crap($analysis['units'], $coverage, $crapThreshold);
                if ($analysis['units'] !== []) {
                    $progress->update(1, 1);
                }
            } else {
                $progress->skip(2, 'Calculating CRAP', 'disabled or not selected');
            }
            $candidates = [];
            if ($runDry && $analysis['units'] !== []) {
                $candidates = Metrics::dry(
                    $analysis['units'],
                    $dryThreshold,
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
            } else {
                $progress->skip(3, 'Comparing duplicates', $runDry ? 'no units' : 'disabled or not selected');
            }
            $report = [
                'schema_version' => self::VERSION,
                'tool' => $command,
                'entries' => $entries,
                'candidates' => $candidates,
                'diagnostics' => [],
            ];
            $serialized = Reports::json($report);
            $directory = $root . '/' . $config['reports']['directory'];
            $reports = [];
            if (in_array('json', $config['reports']['formats'], true)) {
                $reports[$directory . '/' . $command . '.json'] = $serialized;
            }
            if (in_array('edn', $config['reports']['formats'], true)) {
                if ($runCrap) {
                    $reports[$directory . '/crap.edn'] = Reports::crapEdn($entries);
                }
                if ($runDry) {
                    $reports[$directory . '/dry.edn'] = Reports::dryEdn($candidates);
                }
            }
            $paths = [];
            if ($reports === []) {
                $progress->skip(4, 'Saving reports', 'no report formats enabled for selected checks');
            } else {
                $progress->stage(4, 'Saving reports', count($reports));
                foreach ($reports as $path => $content) {
                    Reports::atomic($path, $content);
                    $paths[] = $path;
                    $progress->update(count($paths), count($reports), $path);
                }
            }
            $progress->complete();
            $hasFailure = count(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['exceeded_threshold'],
            )) > 0;
            $unknown = count(array_filter($entries, static fn (array $entry): bool => $entry['coverage'] === null));
            $exitCode = in_array('exceeded_crap_threshold', $config['quality']['fail_on'], true) && $hasFailure ? 2 : 0;
            $hasWarning = $unknown > 0 || $hasFailure || $candidates !== []
                || $files === [] || (!$runCrap && !$runDry);
            $status = $exitCode === 2 ? 'FAIL' : ($hasWarning ? 'WARN' : 'PASS');
            $unitCount = count($analysis['units']);
            echo $format === 'json' ? $serialized : TextReport::render(compact(
                'root', 'command', 'changed', 'files', 'unitCount', 'entries', 'candidates',
                'runCrap', 'runDry', 'crapThreshold', 'dryThreshold', 'paths', 'status', 'exitCode', 'verbose',
            ));
            return $exitCode;
        } catch (Throwable $error) {
            $progress->fail();
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            if ($format === 'text') {
                fwrite(STDERR, "Result: FAIL (exit 3) | analysis incomplete; no successful result for this run.\n");
            }
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
        $progressMode = 'auto';
        $verbose = false;
        $tools = false;
        $minimum = null;
        $namespace = null;
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
            } elseif ($command === null && in_array($argument, ['doctor', 'check', 'crap', 'dry', 'mutate', 'uml'], true)) {
                $command = $argument;
            } elseif ($command === 'doctor' && $argument === '--tools') {
                $tools = true;
            } elseif ($command === 'mutate' && $argument === '--min-msi') {
                $value = $arguments[++$index] ?? '';
                if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 100) {
                    throw new InvalidArgumentException('--min-msi must be 0..100');
                }
                $minimum = (float) $value;
            } elseif ($command === 'uml' && $argument === '--namespace') {
                $namespace = $arguments[++$index] ?? '';
                if ($namespace === '' || !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $namespace)) {
                    throw new InvalidArgumentException('--namespace needs a PHP namespace prefix');
                }
            } elseif ($command !== null && $argument === '--format') {
                $format = $arguments[++$index] ?? '';
                if (!in_array($format, ['text', 'json'], true)) {
                    throw new InvalidArgumentException('--format must be text or json');
                }
            } elseif ($command !== null && $command !== 'doctor' && $argument === '--progress') {
                $progressMode = $arguments[++$index] ?? '';
                if (!in_array($progressMode, ['auto', 'always', 'never'], true)) {
                    throw new InvalidArgumentException('--progress must be auto, always or never');
                }
            } elseif ($command !== null && $command !== 'doctor' && $argument === '--verbose') {
                $verbose = true;
            } elseif ($command !== null && in_array($command, ['check', 'crap', 'dry', 'mutate'], true) && $argument === '--changed') {
                $changed = true;
            } elseif ($command !== null && in_array($command, ['check', 'crap', 'dry'], true) && $argument === '--threshold') {
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
        return [$root, $config, $command, $format, $changed, $threshold, $progressMode, $verbose, $tools, $minimum, $namespace];
    }

    private function doctor(string $root, string $format, bool $tools, ?string $configPath): int
    {
        if ($tools) {
            try {
                $config = Config::load($root, $configPath);
                try {
                    $version = Mutation\InfectionRunner::version($root, $config);
                    $mutation = ['available' => true, 'version' => $version, 'reason' => null];
                } catch (Throwable $error) {
                    $mutation = ['available' => false, 'version' => null, 'reason' => $error->getMessage()];
                }
                $report = ['schema_version' => '1.0', 'tool' => 'doctor-tools', 'tools' => [
                    'mutation' => $mutation,
                    'uml' => ['available' => true, 'engine' => 'php-parser'],
                    'process_control' => ['available' => function_exists('posix_setsid') && function_exists('posix_kill') && function_exists('pcntl_async_signals')],
                    'coverage_runtime' => ['available' => extension_loaded('xdebug') || extension_loaded('pcov') || PHP_SAPI === 'phpdbg'],
                ]];
                echo $format === 'json' ? Reports::json($report)
                    : 'Mutation: ' . ($mutation['available'] ? 'Infection ' . $mutation['version'] : 'unavailable: ' . $mutation['reason'])
                        . "\nUML: available (static PHP)\n";
                return 0;
            } catch (Throwable $error) {
                fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
                return 3;
            }
        }
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
}
