<?php

declare(strict_types=1);

namespace UbModePhp;

use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use UbModePhp\Analyzer\Analyzer;
use UbModePhp\Mutation\InfectionRunner;

final class DoctorCommand
{
    public static function run(Options $options): int
    {
        return $options->tools ? self::tools($options) : self::environment($options);
    }

    private static function tools(Options $options): int
    {
        try {
            $config = Config::load($options->root, $options->configPath);
            try {
                $version = InfectionRunner::version($options->root, $config);
                $mutation = ['available' => true, 'version' => $version, 'reason' => null];
            } catch (Throwable $error) {
                $mutation = ['available' => false, 'version' => null, 'reason' => $error->getMessage()];
            }
            $report = ['schema_version' => Reports::SCHEMA_VERSION, 'tool' => 'doctor-tools', 'tools' => [
                'mutation' => $mutation,
                'uml' => ['available' => true, 'engine' => 'php-parser'],
                'process_control' => ['available' => function_exists('posix_setsid') && function_exists('posix_kill') && function_exists('pcntl_async_signals')],
                'coverage_runtime' => ['available' => extension_loaded('xdebug') || extension_loaded('pcov') || PHP_SAPI === 'phpdbg'],
            ]];
            echo $options->format === 'json' ? Reports::json($report)
                : 'Mutation: ' . ($mutation['available'] ? 'Infection ' . $mutation['version'] : 'unavailable: ' . $mutation['reason'])
                    . "\nUML: available (static PHP)\n";
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            return 3;
        }
    }

    private static function environment(Options $options): int
    {
        $checks = [
            'php_version_id' => PHP_VERSION_ID,
            'php_supported' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'analyzer' => class_exists(Analyzer::class),
            'composer_dependencies' => class_exists(ParserFactory::class)
                && class_exists(Yaml::class),
            'project' => is_dir($options->root),
        ];
        if ($options->format === 'json') {
            echo Reports::json(['schema_version' => Reports::SCHEMA_VERSION, 'checks' => $checks]);
        } else {
            foreach ($checks as $key => $value) {
                echo $key . ': ' . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . "\n";
            }
        }
        return $checks['php_supported'] && $checks['analyzer']
            && $checks['composer_dependencies'] && $checks['project'] ? 0 : 3;
    }
}
