<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use UbModePhp\Config;

final class Topology
{
    public static function extract(string $root, array $files, ?callable $progress = null): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $nodes = [];
        $edges = [];
        $canonical = [];
        $duplicates = [];
        $anonymous = 0;
        foreach ($files as $index => $file) {
            try {
                $ast = $parser->parse(file_get_contents(Config::projectPath($root, $file))) ?? [];
                $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
                foreach ((new NodeFinder())->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
                    if ($class->name === null) {
                        $anonymous++;
                        continue;
                    }
                    $id = $class->namespacedName->toString();
                    $duplicate = $canonical[strtolower($id)] ?? null;
                    $canonical[strtolower($id)] ??= $id;
                    $parent = $class instanceof Node\Stmt\Class_ ? $class->extends?->toString() : null;
                    $kind = match (true) {
                        $class instanceof Node\Stmt\Interface_ => 'interface',
                        $class instanceof Node\Stmt\Trait_ => 'trait',
                        $class instanceof Node\Stmt\Enum_ => 'enum',
                        default => 'class',
                    };
                    $parts = explode('\\', $id);
                    array_pop($parts);
                    $node = [
                        'id' => $id, 'name' => $class->name->toString(), 'namespace' => implode('\\', $parts),
                        'kind' => $kind, 'external' => false, 'file' => $file,
                        'start_line' => $class->getStartLine(), 'end_line' => $class->getEndLine(),
                        'abstract' => $class instanceof Node\Stmt\Class_ && $class->isAbstract(),
                        'final' => $class instanceof Node\Stmt\Class_ && $class->isFinal(), 'members' => [],
                    ];
                    if ($parent !== null) {
                        self::edge($edges, $id, $parent, 'extends');
                    }
                    if ($class instanceof Node\Stmt\Interface_) {
                        foreach ($class->extends as $name) {
                            self::edge($edges, $id, $name->toString(), 'extends');
                        }
                    }
                    if ($class instanceof Node\Stmt\Class_ || $class instanceof Node\Stmt\Enum_) {
                        foreach ($class->implements as $name) {
                            self::edge($edges, $id, $name->toString(), 'implements');
                        }
                    }
                    foreach ($class->stmts as $statement) {
                        if ($statement instanceof Node\Stmt\ClassMethod) {
                            $parameters = [];
                            foreach ($statement->params as $parameter) {
                                $parameters[] = ['name' => $parameter->var->name, 'type' => self::type($parameter->type)];
                                self::typeEdges($edges, $id, $parent, $parameter->type);
                                if ($parameter->flags !== 0 && strtolower($statement->name->toString()) === '__construct') {
                                    $node['members'][] = [
                                        'kind' => 'property', 'name' => $parameter->var->name,
                                        'type' => self::type($parameter->type), 'visibility' => self::visibility($parameter->flags),
                                        'line' => $parameter->getStartLine(), 'promoted' => true,
                                    ];
                                }
                            }
                            self::typeEdges($edges, $id, $parent, $statement->returnType);
                            $node['members'][] = [
                                'kind' => 'method', 'name' => $statement->name->toString(),
                                'visibility' => self::visibility($statement->flags), 'static' => $statement->isStatic(),
                                'abstract' => $statement->stmts === null, 'parameters' => $parameters,
                                'type' => self::type($statement->returnType), 'line' => $statement->getStartLine(),
                            ];
                        } elseif ($statement instanceof Node\Stmt\Property) {
                            self::typeEdges($edges, $id, $parent, $statement->type);
                            foreach ($statement->props as $property) {
                                $node['members'][] = [
                                    'kind' => 'property', 'name' => $property->name->toString(),
                                    'type' => self::type($statement->type), 'visibility' => self::visibility($statement->flags),
                                    'line' => $property->getStartLine(), 'promoted' => false,
                                ];
                            }
                        } elseif ($statement instanceof Node\Stmt\TraitUse) {
                            foreach ($statement->traits as $trait) {
                                self::edge($edges, $id, $trait->toString(), 'trait-use');
                            }
                        } elseif ($statement instanceof Node\Stmt\EnumCase) {
                            $node['members'][] = ['kind' => 'case', 'name' => $statement->name->toString(),
                                'type' => '', 'visibility' => 'public', 'line' => $statement->getStartLine()];
                        }
                        self::dependencies($statement, $id, $parent, $edges);
                    }
                    if ($duplicate !== null) {
                        $duplicates[$duplicate] ??= [$nodes[$duplicate]];
                        $duplicates[$duplicate][] = $node;
                    } else {
                        $nodes[$id] = $node;
                    }
                }
            } catch (\Throwable $error) {
                throw new RuntimeException($file . ': ' . $error->getMessage(), 0, $error);
            }
            if ($progress !== null) {
                $progress($index + 1, count($files), $file);
            }
        }
        $diagnostics = [];
        foreach ($duplicates as $id => $definitions) {
            $nodes[$id] = array_merge($nodes[$id], [
                'kind' => 'ambiguous', 'file' => null, 'start_line' => null, 'end_line' => null,
                'abstract' => false, 'final' => false, 'members' => [], 'definitions' => $definitions,
            ]);
            $diagnostics[] = 'Ambiguous type ' . $id . ': ' . implode(', ', array_map(
                static fn (array $definition): string => $definition['file'] . ':' . $definition['start_line'], $definitions));
        }
        $omitted = 0;
        $resolved = [];
        foreach ($edges as $edge) {
            $edge['from'] = $canonical[strtolower($edge['from'])] ?? $edge['from'];
            if (isset($duplicates[$edge['from']])) {
                $omitted++;
                continue;
            }
            $edge['to'] = $canonical[strtolower($edge['to'])] ?? $edge['to'];
            self::edge($resolved, $edge['from'], $edge['to'], $edge['kind']);
        }
        $edges = $resolved;
        foreach ($edges as $edge) {
            $target = $edge['to'];
            if (!isset($nodes[$target])) {
                $parts = explode('\\', $target);
                $name = array_pop($parts);
                $nodes[$target] = ['id' => $target, 'name' => $name, 'namespace' => implode('\\', $parts),
                    'kind' => 'external', 'external' => true, 'file' => null, 'start_line' => null,
                    'end_line' => null, 'abstract' => false, 'final' => false, 'members' => []];
            }
        }
        ksort($nodes, SORT_STRING);
        ksort($edges, SORT_STRING);
        $result = ['nodes' => array_values($nodes), 'edges' => array_values($edges), 'anonymous_classes_omitted' => $anonymous];
        if ($duplicates !== []) {
            $result['diagnostics'] = $diagnostics;
            $result['ambiguous_edges_omitted'] = $omitted;
        }
        return $result;
    }

    private static function visibility(int $flags): string
    {
        return $flags & Node\Stmt\Class_::MODIFIER_PRIVATE ? 'private'
            : ($flags & Node\Stmt\Class_::MODIFIER_PROTECTED ? 'protected' : 'public');
    }

    private static function type(?Node $type): string
    {
        if ($type === null) {
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
        return $type->toString();
    }

    private static function typeEdges(array &$edges, string $id, ?string $parent, ?Node $type): void
    {
        if ($type instanceof Node\Name) {
            $name = self::resolve($type, $id, $parent);
            if ($name !== null) {
                self::edge($edges, $id, $name, 'type');
            }
        } elseif ($type instanceof Node\NullableType) {
            self::typeEdges($edges, $id, $parent, $type->type);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $part) {
                self::typeEdges($edges, $id, $parent, $part);
            }
        }
    }

    private static function resolve(Node\Name $name, string $id, ?string $parent): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $id,
            'parent' => $parent,
            default => $name->toString(),
        };
    }

    private static function dependencies(Node $node, string $id, ?string $parent, array &$edges): void
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            return;
        }
        if (($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall) && $node->class instanceof Node\Name) {
            $target = self::resolve($node->class, $id, $parent);
            if ($target !== null) {
                self::edge($edges, $id, $target, $node instanceof Node\Expr\New_ ? 'new' : 'static-call');
            }
        }
        foreach ($node->getSubNodeNames() as $field) {
            $children = $node->$field;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    self::dependencies($child, $id, $parent, $edges);
                }
            }
        }
    }

    private static function edge(array &$edges, string $from, string $to, string $kind): void
    {
        if ($from !== $to) {
            $edges[$kind . ':' . $from . ':' . $to] = ['from' => $from, 'to' => $to, 'kind' => $kind];
        }
    }
}
