<?php

declare(strict_types=1);

namespace UbModePhp;

final class Paths
{
    public static function isBelow(string $path, string $base): bool
    {
        return str_starts_with($path, $base . '/');
    }

    public static function isAtOrBelow(string $path, string $base): bool
    {
        return $path === $base || self::isBelow($path, $base);
    }
}
