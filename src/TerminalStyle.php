<?php

declare(strict_types=1);

namespace UbModePhp;

final readonly class TerminalStyle
{
    private bool $color;

    public function __construct(mixed $stream)
    {
        $this->color = function_exists('stream_isatty') && stream_isatty($stream)
            && getenv('NO_COLOR') === false && getenv('TERM') !== 'dumb';
    }

    public function paint(string $text, string $code): string
    {
        return $this->color ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
