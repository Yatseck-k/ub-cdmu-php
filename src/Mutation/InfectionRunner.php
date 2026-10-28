<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

use ColinODell\Json5\Json5Decoder;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use UbModePhp\Config;
use UbModePhp\Progress;
use UbModePhp\ReportRetention;
use UbModePhp\Reports;

final class InfectionRunner
{
    public const VERSION = '0.29.14';

    public static function version(string $root, array $config): string
    {
        $executable = Config::projectPath($root, $config['mutator']['executable']);
        if (!is_file($executable)) {
            throw new RuntimeException('Infection executable is not a file');
        }
        $process = new Process([PHP_BINARY, $executable, '--version', '--no-ansi'], $root, null, null, 10);
        $process->run();
        if (!$process->isSuccessful() || !preg_match('/\b(\d+\.\d+\.\d+)\b/', $process->getOutput(), $match)) {
            throw new RuntimeException('cannot identify Infection version');
        }
        if ($match[1] !== self::VERSION) {
            throw new RuntimeException('unsupported Infection ' . $match[1] . '; supported: ' . self::VERSION);
        }
        return $match[1];
    }

    public static function run(string $root, array $config, array $files, Progress $progress): array
    {
        if (!function_exists('posix_setsid') || !function_exists('posix_kill') || !function_exists('pcntl_async_signals')) {
            throw new RuntimeException('mutation process isolation needs POSIX and PCNTL');
        }
        $version = self::version($root, $config);
        $configFile = Config::projectPath($root, $config['mutator']['configuration']);
        if (dirname($configFile) !== realpath($root)) {
            throw new RuntimeException('Infection configuration must resolve directly inside the project root');
        }
        $settings = Json5Decoder::decode(Reports::read($configFile), true);
        if (
            !is_array($settings) || !is_array($settings['source']['directories'] ?? null)
            || !array_is_list($settings['source']['directories'])
        ) {
            throw new RuntimeException('Infection source.directories must be a list of directories');
        }
        foreach ($settings['source']['directories'] as $directory) {
            if (!is_dir(Config::enginePath($root, $directory, 'Infection source.directories'))) {
                throw new RuntimeException('Infection source.directories must contain directories');
            }
        }
        foreach (['phpUnit', 'phpStan'] as $tool) {
            foreach (['configDir', 'customPath'] as $key) {
                if (isset($settings[$tool][$key])) {
                    Config::enginePath($root, $settings[$tool][$key], $tool . '.' . $key);
                }
            }
        }
        if (isset($settings['bootstrap'])) {
            Config::enginePath($root, $settings['bootstrap'], 'Infection bootstrap');
        } else {
            unset($settings['bootstrap']);
        }
        foreach ($files as $file) {
            if (str_contains($file, ',')) {
                throw new RuntimeException('Infection filter cannot represent a comma in path: ' . $file);
            }
        }
        $run = ReportRetention::startRun($root, $config);
        $directory = $run['directory'];
        $temporary = $root . '/.ub-infection-' . basename($directory) . '.json';
        $resultPath = $directory . '/engine-exit.json';
        $settings['logs'] = ['json' => $directory . '/raw.json'];
        $settings['tmpDir'] = $directory . '/tmp';
        $filters = array_map(static fn (string $file): string => '~^' . preg_quote(Config::projectPath($root, $file), '~') . '$~D', $files);
        $arguments = [PHP_BINARY, __DIR__ . '/ProcessSupervisor.php',
            Config::projectPath($root, $config['mutator']['executable']), $resultPath,
            '--configuration=' . $temporary, '--filter=' . implode(',', $filters),
            '--threads=' . $config['mutator']['threads'], '--min-msi=0', '--min-covered-msi=0',
            '--ignore-msi-with-no-mutations', '--log-verbosity=all', '--no-progress', '--no-interaction', '--no-ansi'];
        // Timeout must not let Symfony reap the session leader before group cleanup.
        $process = new Process($arguments, $root, null, null, null);
        $log = null;
        $handlers = [];
        $oldAsync = pcntl_async_signals(true);
        $error = null;
        $result = null;
        $tail = '';
        $stopped = false;
        try {
            $log = fopen($directory . '/engine.log', 'wb');
            if ($log === false) {
                throw new RuntimeException('cannot create Infection log');
            }
            Reports::atomic($temporary, Reports::json($settings));
            foreach ([SIGINT, SIGTERM] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function (): never {
                    throw new RuntimeException('mutation run interrupted');
                });
            }
            $progress->activity(2, 'Running Infection (baseline tests and mutants)');
            $started = microtime(true);
            $lastTick = $started;
            $tail = '';
            $process->start(static function (string $type, string $buffer) use ($log, &$tail): void {
                if (fwrite($log, $buffer) === false) {
                    throw new RuntimeException('cannot write Infection log');
                }
                $tail = substr($tail . $buffer, -3000);
            });
            $result = null;
            while ($process->isRunning()) {
                if (is_file($resultPath)) {
                    $result = json_decode(Reports::read($resultPath), true, 512, JSON_THROW_ON_ERROR);
                    break;
                }
                if (microtime(true) - $started > $config['mutator']['process_timeout']) {
                    throw new RuntimeException('Infection process timeout; log: ' . $directory . '/engine.log');
                }
                $process->clearOutput();
                $process->clearErrorOutput();
                if (microtime(true) - $lastTick >= 5) {
                    $progress->activity(2, 'Running Infection | elapsed ' . (int) (microtime(true) - $started) . 's');
                    $lastTick = microtime(true);
                }
                usleep(20000);
            }
        } catch (Throwable $failure) {
            $error = $failure;
        } finally {
            pcntl_async_signals(false);
            try {
                $stopped = ProcessSupervisor::stop($process);
                if ($process->isStarted()) {
                    // stop() reaps and drains; these reads also cover early supervisor exit.
                    $process->getOutput();
                    $process->getErrorOutput();
                }
            } catch (Throwable $failure) {
                $error ??= $failure;
            }
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($oldAsync);
            if (is_resource($log)) {
                fclose($log);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            if (is_dir($directory . '/tmp') && !is_link($directory . '/tmp')) {
                try {
                    ReportRetention::remove($directory . '/tmp');
                } catch (Throwable $failure) {
                    $error ??= $failure;
                }
            }
        }
        try {
            if ($error instanceof Throwable) {
                throw new RuntimeException($error->getMessage() . "\n" . $tail, 0, $error);
            }
            if (!$stopped) {
                throw new RuntimeException('mutation supervisor ownership lost; cleanup could not be verified' . "\n" . $tail);
            }
            if (!is_array($result) || !is_int($result['exit'] ?? null)) {
                throw new RuntimeException('mutation supervisor exited without a verified engine result' . "\n" . $tail);
            }
            if ($result['exit'] !== 0) {
                throw new RuntimeException('Infection failed (baseline/tool error); log: ' . $directory . '/engine.log' . "\n" . $tail);
            }
            if (!is_file($directory . '/raw.json')) {
                throw new RuntimeException('Infection did not produce a fresh JSON report');
            }
            return ['version' => $version, 'raw' => file_get_contents($directory . '/raw.json'),
                'log' => file_get_contents($directory . '/engine.log'), 'run' => $run];
        } catch (Throwable $failure) {
            ReportRetention::finishRun($root, $config, $run, false);
            throw $failure;
        }
    }
}
