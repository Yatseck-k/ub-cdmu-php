<?php

declare(strict_types=1);

namespace UbModePhp;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UbModePhp\Mutation\MutationCommand;
use UbModePhp\Uml\UmlCommand;

final class Cli
{
    /**
     * @param list<string> $arguments
     * @return int
     */
    public function run(array $arguments): int
    {
        try {
            $options = Options::parse($arguments);
        } catch (InvalidArgumentException $error) {
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            return 4;
        }
        if ($options->command === 'doctor') {
            return DoctorCommand::run($options);
        }
        $progress = new Progress($options->progressEnabled());
        try {
            $root = realpath($options->root);
            if ($root === false || !is_dir($root)) {
                throw new RuntimeException('root directory does not exist');
            }
            $config = Config::load($root, $options->configPath);
            return match ($options->command) {
                'mutate' => MutationCommand::run($root, $config, $this->engineOptions($options, $root), $progress),
                'uml' => UmlCommand::run($root, $config, $this->engineOptions($options, $root), $progress),
                default => CheckCommand::run($root, $config, $options, $progress),
            };
        } catch (Throwable $error) {
            $progress->fail();
            fwrite(STDERR, 'ub-php: ' . $error->getMessage() . "\n");
            if ($options->format === 'text') {
                fwrite(STDERR, "Result: FAIL (exit 3) | analysis incomplete; no successful result for this run.\n");
            }
            return 3;
        }
    }

    /**
     * @param Options $options
     * @param string $root
     * @return array<string, mixed>
     */
    private function engineOptions(Options $options, string $root): array
    {
        return [
            'changed' => $options->changed,
            'format' => $options->format,
            'verbose' => $options->verbose,
            'min_msi' => $options->minimumMsi,
            'namespace' => $options->namespace,
            'config_path' => $options->configPath ?? $root . '/.ub-mode.yaml',
        ];
    }
}
