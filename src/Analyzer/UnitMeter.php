<?php

declare(strict_types=1);

namespace UbModePhp\Analyzer;

use PhpParser\Node;

/** Cyclomatic complexity, node count and normalized structural features of one function body. */
final class UnitMeter
{
    /** @return array{int, int, list<string>} complexity, node count, sorted features */
    public static function measure(array|Node $body): array
    {
        $complexity = 1;
        $count = 0;
        $features = [];
        $visit = function (
            array|Node $value,
            string $parent = ''
        ) use (
            &$visit,
            &$complexity,
            &$count,
            &$features,
        ): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $visit($item, $parent);
                    }
                }
                return;
            }
            if ($value instanceof Node\FunctionLike || $value instanceof Node\Stmt\ClassLike) {
                return; // Nested units receive their own metrics.
            }
            $type = substr(strrchr($value::class, '\\') ?: $value::class, 1);
            $count++;
            $features[] = 'node:' . $type;
            $features[] = 'edge:' . $parent . '/' . $type;
            array_push($features, ...self::calls($value), ...($value instanceof Node\Expr\BinaryOp ? ['op:' . $type] : []));
            if (self::isDecision($value)) {
                $complexity++;
            }
            foreach ($value->getSubNodeNames() as $field) {
                $child = $value->$field;
                if ($child instanceof Node || is_array($child)) {
                    $visit($child, $type);
                }
            }
        };
        $visit($body);
        $features = array_values(array_unique($features));
        sort($features, SORT_STRING);
        return [$complexity, $count, $features];
    }

    /** @return list<string> a `call:` feature for direct function, method and static calls */
    private static function calls(Node $node): array
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            return ['call:' . strtolower($node->name->toString())];
        }
        if (
            ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier
        ) {
            return ['call:' . strtolower($node->name->toString())];
        }
        return [];
    }

    private static function isDecision(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Catch_
            || ($node instanceof Node\Stmt\Case_ && $node->cond instanceof Node\Expr)
            || ($node instanceof Node\MatchArm && $node->conds !== null)
            || $node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
            || $node instanceof Node\Expr\BinaryOp\Coalesce;
    }
}
