<?php

declare(strict_types=1);

namespace UbModePhp\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;

/** Anonymous names are numbered per instance, so use one instance per analysis run. */
final class UnitDiscoverer
{
    private int $anonymousSequence = 0;

    /** @var list<array<string, mixed>> */
    private array $units = [];

    /**
     * @param array<mixed> $nodes
     * @param string $file
     */
    public function discover(array $nodes, string $file): void
    {
        $this->walk($nodes, $file, '', null);
    }

    /** @return list<array<string, mixed>> */
    public function units(): array
    {
        return $this->units;
    }

    /**
     * @param array<mixed>|Node $nodes
     * @param string $file
     * @param string $namespace
     * @param ?string $className
     */
    private function walk(array|Node $nodes, string $file, string $namespace, ?string $className): void
    {
        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if ($node instanceof Node) {
                    $this->walk($node, $file, $namespace, $className);
                }
            }
            return;
        }
        if ($nodes instanceof Namespace_) {
            $this->walk($nodes->stmts, $file, $nodes->name?->toString() ?? '', null);
            return;
        }
        if ($nodes instanceof ClassLike) {
            $name = $nodes->name?->toString()
                ?? 'anonymous@' . $nodes->getStartLine() . '#' . ++$this->anonymousSequence;
            $this->walk($nodes->stmts, $file, $namespace, $name);
            return;
        }
        $this->record($nodes, $file, $namespace, $className);
        foreach ($nodes->getSubNodeNames() as $field) {
            $child = $nodes->$field;
            if ($child instanceof Node || is_array($child)) {
                $this->walk($child, $file, $namespace, $className);
            }
        }
    }

    private function record(Node $node, string $file, string $namespace, ?string $className): void
    {
        $kind = null;
        $name = null;
        $body = null;
        if ($node instanceof Function_) {
            $kind = 'function';
            $name = $node->name->toString();
            $body = $node->stmts;
        } elseif ($node instanceof ClassMethod && $node->stmts !== null) {
            $kind = 'method';
            $name = $node->name->toString();
            $body = $node->stmts;
        } elseif ($node instanceof Closure) {
            $kind = 'closure';
            $name = 'closure@' . $node->getStartLine() . '#' . ++$this->anonymousSequence;
            $body = $node->stmts;
        } elseif ($node instanceof ArrowFunction) {
            $kind = 'arrow';
            $name = 'arrow@' . $node->getStartLine() . '#' . ++$this->anonymousSequence;
            $body = $node->expr;
        }
        if ($kind === null) {
            return;
        }
        $scope = implode('\\', array_filter(
            [$namespace, $className],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));
        [$complexity, $nodeCount, $features] = UnitMeter::measure($body);
        $line = $node->getStartLine();
        $this->units[] = [
            'id' => $file . ':' . $scope . ':' . $name . ':' . $line,
            'file' => $file,
            'namespace' => $scope,
            'name' => $name,
            'kind' => $kind,
            'start_line' => $line,
            'end_line' => $node->getEndLine(),
            'complexity' => $complexity,
            'node_count' => $nodeCount,
            'features' => $features,
        ];
    }
}
