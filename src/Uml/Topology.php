<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use Throwable;
use UbModePhp\Config;
use UbModePhp\Reports;

final class Topology
{
    public static function extract(string $root, array $files, ?callable $progress = null): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $nodes = [];
        $edges = new EdgeCollector();
        $canonical = [];
        $duplicates = [];
        $anonymous = 0;
        foreach ($files as $index => $file) {
            try {
                $ast = $parser->parse(Reports::read(Config::projectPath($root, $file))) ?? [];
                $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
                foreach ((new NodeFinder())->findInstanceOf($ast, ClassLike::class) as $class) {
                    if ($class->name === null) {
                        $anonymous++;
                        continue;
                    }
                    $id = ($class->namespacedName ?? $class->name)->toString();
                    $duplicate = $canonical[strtolower($id)] ?? null;
                    $canonical[strtolower($id)] ??= $id;
                    $node = ClassReader::read($class, $id, $file, $edges);
                    if ($duplicate !== null) {
                        $duplicates[$duplicate] ??= [$nodes[$duplicate]];
                        $duplicates[$duplicate][] = $node;
                    } else {
                        $nodes[$id] = $node;
                    }
                }
            } catch (Throwable $error) {
                throw new RuntimeException($file . ': ' . $error->getMessage(), 0, $error);
            }
            if ($progress !== null) {
                $progress($index + 1, count($files), $file);
            }
        }
        $diagnostics = self::markAmbiguous($nodes, $duplicates);
        [$resolved, $omitted] = self::resolveEdges($edges->all(), $canonical, $duplicates);
        self::addExternal($nodes, $resolved);
        ksort($nodes, SORT_STRING);
        ksort($resolved, SORT_STRING);
        $result = ['nodes' => array_values($nodes), 'edges' => array_values($resolved), 'anonymous_classes_omitted' => $anonymous];
        if ($duplicates !== []) {
            $result['diagnostics'] = $diagnostics;
            $result['ambiguous_edges_omitted'] = $omitted;
        }
        return $result;
    }

    /**
     * @param array $nodes
     * @param array<string, list<array<string, mixed>>> $duplicates definitions by canonical id
     * @return list<string> diagnostics
     */
    private static function markAmbiguous(array &$nodes, array $duplicates): array
    {
        $diagnostics = [];
        foreach ($duplicates as $id => $definitions) {
            $nodes[$id] = array_merge($nodes[$id], [
                'kind' => 'ambiguous', 'file' => null, 'start_line' => null, 'end_line' => null,
                'abstract' => false, 'final' => false, 'members' => [], 'definitions' => $definitions,
            ]);
            $diagnostics[] = 'Ambiguous type ' . $id . ': ' . implode(', ', array_map(
                static fn (array $definition): string => $definition['file'] . ':' . $definition['start_line'],
                $definitions
            ));
        }
        return $diagnostics;
    }

    /**
     * @param array $edges
     * @param array $canonical
     * @param array $duplicates
     * @return array{0: array<string, array<string, string>>, 1: int} resolved edges, number omitted
     */
    private static function resolveEdges(array $edges, array $canonical, array $duplicates): array
    {
        $omitted = 0;
        $resolved = new EdgeCollector();
        foreach ($edges as $edge) {
            $edge['from'] = $canonical[strtolower($edge['from'])] ?? $edge['from'];
            if (isset($duplicates[$edge['from']])) {
                $omitted++;
                continue;
            }
            $edge['to'] = $canonical[strtolower($edge['to'])] ?? $edge['to'];
            $resolved->add($edge['from'], $edge['to'], $edge['kind']);
        }
        return [$resolved->all(), $omitted];
    }

    private static function addExternal(array &$nodes, array $edges): void
    {
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
    }
}
