<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Node;

/** Renders declared types and modifier flags the way the UML views show them. */
final class TypeFormatter
{
    public static function visibility(int $flags): string
    {
        return $flags & Node\Stmt\Class_::MODIFIER_PRIVATE ? 'private'
            : ($flags & Node\Stmt\Class_::MODIFIER_PROTECTED ? 'protected' : 'public');
    }

    public static function type(?Node $type): string
    {
        if (!$type instanceof Node) {
            return '';
        }
        if ($type instanceof Node\NullableType) {
            return '?' . self::type($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = array_map(static function (Node $part) use ($type): string {
                $value = self::type($part);
                return $type instanceof Node\UnionType && $part instanceof Node\IntersectionType ? '(' . $value . ')' : $value;
            }, $type->types);
            return implode($type instanceof Node\UnionType ? '|' : '&', $parts);
        }
        return $type instanceof Node\Name || $type instanceof Node\Identifier ? $type->toString() : '';
    }
}
