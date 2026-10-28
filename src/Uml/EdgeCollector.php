<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\UnionType;

final class EdgeCollector
{
    /** @var array<string, array{from: string, to: string, kind: string}> */
    private array $edges = [];

    public function add(string $from, string $to, string $kind): void
    {
        if ($from !== $to) {
            $this->edges[$kind . ':' . $from . ':' . $to] = ['from' => $from, 'to' => $to, 'kind' => $kind];
        }
    }

    /** @return array<string, array{from: string, to: string, kind: string}> */
    public function all(): array
    {
        return $this->edges;
    }

    public function type(string $id, ?string $parent, ?Node $type): void
    {
        if ($type instanceof Name) {
            $name = $this->resolve($type, $id, $parent);
            if ($name !== null) {
                $this->add($id, $name, 'type');
            }
        } elseif ($type instanceof NullableType) {
            $this->type($id, $parent, $type->type);
        } elseif ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $part) {
                $this->type($id, $parent, $part);
            }
        }
    }

    public function dependencies(Node $node, string $id, ?string $parent): void
    {
        if ($node instanceof ClassLike) {
            return;
        }
        if (($node instanceof New_ || $node instanceof StaticCall) && $node->class instanceof Name) {
            $target = $this->resolve($node->class, $id, $parent);
            if ($target !== null) {
                $this->add($id, $target, $node instanceof New_ ? 'new' : 'static-call');
            }
        }
        foreach ($node->getSubNodeNames() as $field) {
            $children = $node->$field;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->dependencies($child, $id, $parent);
                }
            }
        }
    }

    private function resolve(Name $name, string $id, ?string $parent): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $id,
            'parent' => $parent,
            default => $name->toString(),
        };
    }
}
