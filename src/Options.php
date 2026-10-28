<?php

declare(strict_types=1);

namespace UbModePhp;

use InvalidArgumentException;

/** Validated command-line options; the only place that interprets raw arguments. */
final readonly class Options
{
    private const COMMANDS = ['doctor', 'check', 'crap', 'dry', 'mutate', 'uml'];

    public function __construct(
        public string $root,
        public ?string $configPath,
        public string $command,
        public string $format = 'text',
        public bool $changed = false,
        public ?float $threshold = null,
        public string $progressMode = 'auto',
        public bool $verbose = false,
        public bool $tools = false,
        public ?float $minimumMsi = null,
        public ?string $namespace = null,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments): self
    {
        $values = ['root' => getcwd() ?: '.', 'configPath' => null, 'command' => null];
        $count = count($arguments);
        for ($index = 0; $index < $count; $index++) {
            $argument = $arguments[$index];
            $command = $values['command'];
            if ($command === null && in_array($argument, ['--root', '--config'], true)) {
                $value = $arguments[++$index] ?? null;
                if ($value === null || $value === '') {
                    throw new InvalidArgumentException($argument . ' needs a value');
                }
                $values[$argument === '--root' ? 'root' : 'configPath'] = $value;
            } elseif ($command === null && in_array($argument, self::COMMANDS, true)) {
                $values['command'] = $argument;
            } elseif ($command === null) {
                throw new InvalidArgumentException('invalid command or option: ' . $argument);
            } else {
                $index = self::commandOption($values, $command, $argument, $arguments, $index);
            }
        }
        if ($values['command'] === null) {
            throw new InvalidArgumentException('missing command');
        }
        return new self(...$values);
    }

    /**
     * Reads one option that belongs to an already selected command and returns the index of its last token.
     *
     * @param array<string, mixed> $values
     * @param list<string> $arguments
     */
    private static function commandOption(array &$values, string $command, string $argument, array $arguments, int $index): int
    {
        $runs = $command !== 'doctor';
        if ($command === 'doctor' && $argument === '--tools') {
            $values['tools'] = true;
        } elseif ($command === 'mutate' && $argument === '--min-msi') {
            $value = $arguments[++$index] ?? '';
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 100) {
                throw new InvalidArgumentException('--min-msi must be 0..100');
            }
            $values['minimumMsi'] = (float) $value;
        } elseif ($command === 'uml' && $argument === '--namespace') {
            $namespace = $arguments[++$index] ?? '';
            if ($namespace === '' || !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $namespace)) {
                throw new InvalidArgumentException('--namespace needs a PHP namespace prefix');
            }
            $values['namespace'] = $namespace;
        } elseif ($argument === '--format') {
            $format = $arguments[++$index] ?? '';
            if (!in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('--format must be text or json');
            }
            $values['format'] = $format;
        } elseif ($runs && $argument === '--progress') {
            $mode = $arguments[++$index] ?? '';
            if (!in_array($mode, ['auto', 'always', 'never'], true)) {
                throw new InvalidArgumentException('--progress must be auto, always or never');
            }
            $values['progressMode'] = $mode;
        } elseif ($runs && $argument === '--verbose') {
            $values['verbose'] = true;
        } elseif (in_array($command, ['check', 'crap', 'dry', 'mutate'], true) && $argument === '--changed') {
            $values['changed'] = true;
        } elseif (in_array($command, ['check', 'crap', 'dry'], true) && $argument === '--threshold') {
            $value = $arguments[++$index] ?? null;
            if (
                $command === 'check' || $value === null || !is_numeric($value)
                || !is_finite((float) $value) || (float) $value <= 0
            ) {
                throw new InvalidArgumentException('--threshold applies to crap or dry and must be positive');
            }
            $values['threshold'] = (float) $value;
        } else {
            throw new InvalidArgumentException('invalid command or option: ' . $argument);
        }
        return $index;
    }

    public function progressEnabled(): bool
    {
        return $this->progressMode === 'always' || ($this->progressMode === 'auto' && $this->format === 'text');
    }
}
