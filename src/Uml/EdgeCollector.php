<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Node;

/** Collects de-duplicated relations (extends, implements, trait-use, type, new, static-call) between types. */
final class EdgeCollector
{
    /** @var array<string, array{from: string, to: string, kind: string}> keyed by kind:from:to */
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
        if ($type instanceof Node\Name) {
            $name = $this->resolve($type, $id, $parent);
            if ($name !== null) {
                $this->add($id, $name, 'type');
            }
        } elseif ($type instanceof Node\NullableType) {
            $this->type($id, $parent, $type->type);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $part) {
                $this->type($id, $parent, $part);
            }
        }
    }

    /** Records `new` and static-call targets found anywhere below a class statement. */
    public function dependencies(Node $node, string $id, ?string $parent): void
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            return;
        }
        if (($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall) && $node->class instanceof Node\Name) {
            $target = $this->resolve($node->class, $id, $parent);
            if ($target !== null) {
                $this->add($id, $target, $node instanceof Node\Expr\New_ ? 'new' : 'static-call');
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

    private function resolve(Node\Name $name, string $id, ?string $parent): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $id,
            'parent' => $parent,
            default => $name->toString(),
        };
    }
}
