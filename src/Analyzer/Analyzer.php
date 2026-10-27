<?php

declare(strict_types=1);

namespace UbModePhp\Analyzer;

use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

final class Analyzer
{
    private int $anonymousSequence = 0;

    /**
     * @param list<mixed> $files
     * @return array{
     *     schema_version: string,
     *     units: list<array<string, mixed>>,
     *     diagnostics: list<array<string, string>>
     * }
     * @param (callable(int, int, string): void)|null $progress
     */
    public function analyze(string $root, array $files, ?callable $progress = null): array
    {
        $this->anonymousSequence = 0;
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException('root directory does not exist');
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $units = [];
        $diagnostics = [];
        $seen = [];
        $totalFiles = count($files);
        foreach ($files as $index => $file) {
            if (
                !is_string($file) || $file === '' || str_starts_with($file, '/')
                || str_contains($file, '\\') || str_contains($file, "\0")
            ) {
                throw new InvalidArgumentException('files must contain relative POSIX paths');
            }
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $parts = explode('/', $file);
            if (
                in_array('..', $parts, true) || in_array('.', $parts, true)
                || pathinfo($file, PATHINFO_EXTENSION) !== 'php'
            ) {
                throw new InvalidArgumentException('invalid PHP file path');
            }
            $realFile = realpath($realRoot . '/' . $file);
            if (
                $realFile === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)
                || !is_file($realFile)
            ) {
                $diagnostics[] = ['file' => $file, 'message' => 'file is missing or outside project root'];
                if ($progress !== null) {
                    $progress($index + 1, $totalFiles, $file);
                }
                continue;
            }
            try {
                $source = file_get_contents($realFile);
                if ($source === false) {
                    throw new InvalidArgumentException('file cannot be read');
                }
                $nodes = $parser->parse($source) ?? [];
                $this->discover($nodes, $file, '', null, $units);
            } catch (Error $error) {
                $diagnostics[] = ['file' => $file, 'message' => $error->getMessage()];
            } catch (InvalidArgumentException $error) {
                $diagnostics[] = ['file' => $file, 'message' => $error->getMessage()];
            }
            if ($progress !== null) {
                $progress($index + 1, $totalFiles, $file);
            }
        }
        usort($units, static fn (array $a, array $b): int =>
            [$a['file'], $a['start_line'], $a['id']] <=> [$b['file'], $b['start_line'], $b['id']]);
        usort($diagnostics, static fn (array $a, array $b): int =>
            [$a['file'], $a['message']] <=> [$b['file'], $b['message']]);
        return ['schema_version' => '1.0', 'units' => $units, 'diagnostics' => $diagnostics];
    }

    /**
     * @param array<mixed>|Node $nodes
     * @param list<array<string, mixed>> $units
     */
    private function discover(
        array|Node $nodes,
        string $file,
        string $namespace,
        ?string $className,
        array &$units,
    ): void {
        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if ($node instanceof Node) {
                    $this->discover($node, $file, $namespace, $className, $units);
                }
            }
            return;
        }
        if ($nodes instanceof Node\Stmt\Namespace_) {
            $this->discover($nodes->stmts, $file, $nodes->name?->toString() ?? '', null, $units);
            return;
        }
        if ($nodes instanceof Node\Stmt\ClassLike) {
            $name = $nodes->name?->toString()
                ?? 'anonymous@' . $nodes->getStartLine() . '#' . ++$this->anonymousSequence;
            $this->discover($nodes->stmts, $file, $namespace, $name, $units);
            return;
        }
        $kind = null;
        $name = null;
        $body = null;
        if ($nodes instanceof Node\Stmt\Function_) {
            $kind = 'function';
            $name = $nodes->name->toString();
            $body = $nodes->stmts;
        } elseif ($nodes instanceof Node\Stmt\ClassMethod && $nodes->stmts !== null) {
            $kind = 'method';
            $name = $nodes->name->toString();
            $body = $nodes->stmts;
        } elseif ($nodes instanceof Node\Expr\Closure) {
            $kind = 'closure';
            $name = 'closure@' . $nodes->getStartLine() . '#' . ++$this->anonymousSequence;
            $body = $nodes->stmts;
        } elseif ($nodes instanceof Node\Expr\ArrowFunction) {
            $kind = 'arrow';
            $name = 'arrow@' . $nodes->getStartLine() . '#' . ++$this->anonymousSequence;
            $body = $nodes->expr;
        }
        if ($kind !== null) {
            $scope = implode('\\', array_filter(
                [$namespace, $className],
                static fn (?string $part): bool => $part !== null && $part !== '',
            ));
            [$complexity, $nodeCount, $features] = $this->measure($body);
            $line = $nodes->getStartLine();
            $units[] = [
                'id' => $file . ':' . $scope . ':' . $name . ':' . $line,
                'file' => $file,
                'namespace' => $scope,
                'name' => $name,
                'kind' => $kind,
                'start_line' => $line,
                'end_line' => $nodes->getEndLine(),
                'complexity' => $complexity,
                'node_count' => $nodeCount,
                'features' => $features,
            ];
        }
        foreach ($nodes->getSubNodeNames() as $field) {
            $child = $nodes->$field;
            if ($child instanceof Node || is_array($child)) {
                $this->discover($child, $file, $namespace, $className, $units);
            }
        }
    }

    /** @return array{int, int, list<string>} */
    private function measure(array|Node $body): array
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
            if ($value instanceof Node\Expr\FuncCall && $value->name instanceof Node\Name) {
                $features[] = 'call:' . strtolower($value->name->toString());
            } elseif (
                $value instanceof Node\Expr\MethodCall
                || $value instanceof Node\Expr\NullsafeMethodCall
                || $value instanceof Node\Expr\StaticCall
            ) {
                if ($value->name instanceof Node\Identifier) {
                    $features[] = 'call:' . strtolower($value->name->toString());
                }
            }
            if ($value instanceof Node\Expr\BinaryOp) {
                $features[] = 'op:' . $type;
            }
            if ($this->isDecision($value)) {
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

    private function isDecision(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Catch_
            || ($node instanceof Node\Stmt\Case_ && $node->cond !== null)
            || ($node instanceof Node\MatchArm && $node->conds !== null)
            || $node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
            || $node instanceof Node\Expr\BinaryOp\Coalesce;
    }
}
