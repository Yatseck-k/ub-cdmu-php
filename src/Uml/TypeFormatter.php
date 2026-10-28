<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

final class TypeFormatter
{
    public static function visibility(int $flags): string
    {
        return $flags & Modifiers::PRIVATE ? 'private'
            : ($flags & Modifiers::PROTECTED ? 'protected' : 'public');
    }

    public static function type(?Node $type): string
    {
        if (!$type instanceof Node) {
            return '';
        }
        if ($type instanceof NullableType) {
            return '?' . self::type($type->type);
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $parts = array_map(static function (Node $part) use ($type): string {
                $value = self::type($part);
                return $type instanceof UnionType && $part instanceof IntersectionType ? '(' . $value . ')' : $value;
            }, $type->types);
            return implode($type instanceof UnionType ? '|' : '&', $parts);
        }
        return $type instanceof Name || $type instanceof Identifier ? $type->toString() : '';
    }
}
