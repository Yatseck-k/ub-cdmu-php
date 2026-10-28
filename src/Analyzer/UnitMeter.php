<?php

declare(strict_types=1);

namespace UbModePhp\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;

final class UnitMeter
{
    /**
     * @param array|Node $body
     * @return array{int, int, list<string>} complexity, node count, sorted features
     */
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
            if ($value instanceof FunctionLike || $value instanceof ClassLike) {
                return; // Nested units receive their own metrics.
            }
            $type = substr(strrchr($value::class, '\\') ?: $value::class, 1);
            $count++;
            $features[] = 'node:' . $type;
            $features[] = 'edge:' . $parent . '/' . $type;
            array_push($features, ...self::calls($value), ...($value instanceof BinaryOp ? ['op:' . $type] : []));
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

    /**
     * @param Node $node
     * @return list<string> a `call:` feature for direct function, method and static calls
     */
    private static function calls(Node $node): array
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            return ['call:' . strtolower($node->name->toString())];
        }
        if (
            ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier
        ) {
            return ['call:' . strtolower($node->name->toString())];
        }
        return [];
    }

    private static function isDecision(Node $node): bool
    {
        return $node instanceof If_
            || $node instanceof ElseIf_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Catch_
            || ($node instanceof Case_ && $node->cond instanceof Expr)
            || ($node instanceof MatchArm && $node->conds !== null)
            || $node instanceof Ternary
            || $node instanceof BooleanAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalAnd
            || $node instanceof LogicalOr
            || $node instanceof Coalesce;
    }
}
