<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Types the rows of `Select::fetchAll()` and `fetchOne()` as array shapes when the query is
 * built in one chain from `Select::from()` with literal column names:
 *
 *     Select::from('tasks')->columns('id', 'tasks.title')->column(Sql::raw('COUNT(*)'), 'total')->fetchAll($db)
 *     // list<array{id: mixed, title: mixed, total: mixed}>
 *
 * Values stay mixed: the database decides their types. Queries selecting `*`, an expression
 * without an alias, or built across variables keep the declared `array<string, mixed>`.
 */
final readonly class SelectResultTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const string SELECT = 'Shirakaba\Query\Select';

    public function getClass(): string
    {
        return self::SELECT;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['fetchAll', 'fetchOne'], true);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $columns = self::columns($methodCall->var, $scope);
        if ($columns === null || $columns === []) {
            return null;
        }
        $row = ConstantArrayTypeBuilder::createEmpty();
        foreach ($columns as $column) {
            $row->setOffsetValueType(new ConstantStringType($column), new MixedType());
        }
        $shape = $row->getArray();

        return (
            $methodReflection->getName() === 'fetchAll'
                ? new IntersectionType([new ArrayType(new IntegerType(), $shape), new AccessoryArrayListType()])
                : TypeCombinator::union($shape, new NullType())
        );
    }

    /**
     * The result keys of the chain ending in $expression, or null when they cannot be known.
     *
     * @return list<string>|null
     */
    private static function columns(Expr $expression, Scope $scope): ?array
    {
        $collected = [];
        while ($expression instanceof MethodCall) {
            if (!$expression->name instanceof Identifier) {
                return null;
            }
            $method = $expression->name->toString();
            if (in_array($method, ['columns', 'reselect', 'column'], true)) {
                $keys = [];
                $args = $expression->getArgs();
                if ($method === 'column') {
                    $alias = isset($args[1]) ? $scope->getType($args[1]->value)->getConstantStrings() : [];
                    if (count($alias) !== 1) {
                        return null;
                    }
                    $keys[] = $alias[0]->getValue();
                } else {
                    foreach ($args as $arg) {
                        if ($arg->unpack) {
                            return null;
                        }
                        $names = $scope->getType($arg->value)->getConstantStrings();
                        if (count($names) !== 1) {
                            return null; // Sql or a variable: the key is not known.
                        }
                        $key = self::key($names[0]->getValue());
                        if ($key === null) {
                            return null;
                        }
                        $keys[] = $key;
                    }
                }
                $collected = [...$keys, ...$collected];
                if ($method === 'reselect') {
                    // Earlier columns are replaced.
                    return $keys === [] ? [] : $collected;
                }
            }
            $expression = $expression->var;
        }
        $isFrom =
            $expression instanceof StaticCall
            && $expression->class instanceof Name
            && $scope->resolveName($expression->class) === self::SELECT
            && $expression->name instanceof Identifier
            && $expression->name->toString() === 'from';

        return $isFrom ? $collected : null;
    }

    /**
     * The key PDO returns for a column name: the part after the table, or null for `*`.
     */
    private static function key(string $column): ?string
    {
        $name = substr($column, (int) strrpos('.' . $column, '.'));

        return $name === '*' || $name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 ? null : $name;
    }
}
