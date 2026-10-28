<?php

declare(strict_types=1);

namespace UbModePhp\Uml;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;

final class ClassReader
{
    public static function read(ClassLike $class, string $id, string $file, EdgeCollector $edges): array
    {
        $parent = $class instanceof Class_ ? $class->extends?->toString() : null;
        $parts = explode('\\', $id);
        array_pop($parts);
        $node = [
            'id' => $id, 'name' => $class->name?->toString() ?? $id, 'namespace' => implode('\\', $parts),
            'kind' => self::kind($class), 'external' => false, 'file' => $file,
            'start_line' => $class->getStartLine(), 'end_line' => $class->getEndLine(),
            'abstract' => $class instanceof Class_ && $class->isAbstract(),
            'final' => $class instanceof Class_ && $class->isFinal(), 'members' => [],
        ];
        self::inheritance($class, $id, $parent, $edges);
        foreach ($class->stmts as $statement) {
            array_push($node['members'], ...self::members($statement, $id, $parent, $edges));
            $edges->dependencies($statement, $id, $parent);
        }
        return $node;
    }

    private static function kind(ClassLike $class): string
    {
        return match (true) {
            $class instanceof Interface_ => 'interface',
            $class instanceof Trait_ => 'trait',
            $class instanceof Enum_ => 'enum',
            default => 'class',
        };
    }

    private static function inheritance(ClassLike $class, string $id, ?string $parent, EdgeCollector $edges): void
    {
        if ($parent !== null) {
            $edges->add($id, $parent, 'extends');
        }
        if ($class instanceof Interface_) {
            foreach ($class->extends as $name) {
                $edges->add($id, $name->toString(), 'extends');
            }
        }
        if ($class instanceof Class_ || $class instanceof Enum_) {
            foreach ($class->implements as $name) {
                $edges->add($id, $name->toString(), 'implements');
            }
        }
    }

    /**
     * @param Stmt $statement
     * @param string $id
     * @param ?string $parent
     * @param EdgeCollector $edges
     * @return list<array<string, mixed>> members declared by one class statement; also records type and trait relations
     */
    private static function members(Stmt $statement, string $id, ?string $parent, EdgeCollector $edges): array
    {
        if ($statement instanceof ClassMethod) {
            return self::method($statement, $id, $parent, $edges);
        }
        if ($statement instanceof Property) {
            $edges->type($id, $parent, $statement->type);
            $members = [];
            foreach ($statement->props as $property) {
                $members[] = [
                    'kind' => 'property', 'name' => $property->name->toString(),
                    'type' => TypeFormatter::type($statement->type), 'visibility' => TypeFormatter::visibility($statement->flags),
                    'line' => $property->getStartLine(), 'promoted' => false,
                ];
            }
            return $members;
        }
        if ($statement instanceof TraitUse) {
            foreach ($statement->traits as $trait) {
                $edges->add($id, $trait->toString(), 'trait-use');
            }
            return [];
        }
        if ($statement instanceof EnumCase) {
            return [['kind' => 'case', 'name' => $statement->name->toString(),
                'type' => '', 'visibility' => 'public', 'line' => $statement->getStartLine()]];
        }
        return [];
    }

    /**
     * @param ClassMethod $method
     * @param string $id
     * @param ?string $parent
     * @param EdgeCollector $edges
     * @return list<array<string, mixed>> promoted constructor properties first, then the method itself
     */
    private static function method(ClassMethod $method, string $id, ?string $parent, EdgeCollector $edges): array
    {
        $members = [];
        $parameters = [];
        foreach ($method->params as $parameter) {
            $name = $parameter->var instanceof Variable && is_string($parameter->var->name)
                ? $parameter->var->name : '';
            $parameters[] = ['name' => $name, 'type' => TypeFormatter::type($parameter->type)];
            $edges->type($id, $parent, $parameter->type);
            if ($parameter->flags !== 0 && strtolower($method->name->toString()) === '__construct') {
                $members[] = [
                    'kind' => 'property', 'name' => $name,
                    'type' => TypeFormatter::type($parameter->type), 'visibility' => TypeFormatter::visibility($parameter->flags),
                    'line' => $parameter->getStartLine(), 'promoted' => true,
                ];
            }
        }
        $edges->type($id, $parent, $method->returnType);
        $members[] = [
            'kind' => 'method', 'name' => $method->name->toString(),
            'visibility' => TypeFormatter::visibility($method->flags), 'static' => $method->isStatic(),
            'abstract' => $method->stmts === null, 'parameters' => $parameters,
            'type' => TypeFormatter::type($method->returnType), 'line' => $method->getStartLine(),
        ];
        return $members;
    }
}
