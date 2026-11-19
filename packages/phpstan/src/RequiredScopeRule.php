<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Shirakaba\Orm\Mapping\EntityMap;
use Throwable;

/**
 * Reports ORM calls on an entity with a #[RequiredScope] that cannot have the scope, so a
 * forgotten tenant fails in analysis instead of on the code path that runs:
 *
 * - an EntityQuery that is executed (fetch, first, into, count, exists, update, delete,
 *   select) when its whole chain, from Entities::query() on, passes neither scoped() with an
 *   instance of the scope nor withoutScope() with its class;
 * - Entities::find(), get(), update(), patch(), delete(), restore(), forceDelete(), insert()
 *   and insertAll() without a $scopes argument that can hold the scope, and load() of a
 *   relation to such an entity.
 *
 * Chains built across variables or functions, and scopes whose type is not known, are left
 * to ScopeMissing at run time. Without shirakaba/orm installed the rule does nothing.
 *
 * @implements Rule<Expr>
 */
final readonly class RequiredScopeRule implements Rule
{
    private const string QUERY = 'Shirakaba\Orm\EntityQuery';
    private const string ENTITIES = 'Shirakaba\Orm\Entities';

    /** @var list<string> Methods that run the query. */
    private const array EXECUTE = ['fetch', 'first', 'into', 'count', 'exists', 'update', 'delete', 'select'];

    /** @var array<string, array{int, string}> Entities methods => [position of $scopes, how the entity is given]. */
    private const array STATIC = [
        'find' => [3, 'class'],
        'get' => [3, 'class'],
        'patch' => [5, 'class'],
        'update' => [3, 'object'],
        'delete' => [3, 'object'],
        'restore' => [3, 'object'],
        'forcedelete' => [2, 'object'],
        'insert' => [3, 'object'],
        'insertall' => [4, 'list'],
        'load' => [3, 'relation'],
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!class_exists(EntityMap::class)) {
            return [];
        }
        try {
            return match (true) {
                $node instanceof MethodCall => $this->query($node, $scope),
                $node instanceof StaticCall => $this->entities($node, $scope),
                default => [],
            };
        } catch (Throwable) {
            // Mapping errors are EntityQueryRule's to report.
            return [];
        }
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function query(MethodCall $call, Scope $scope): array
    {
        if (!$call->name instanceof Identifier || !in_array(strtolower($call->name->toString()), self::EXECUTE, true)) {
            return [];
        }
        $type = $scope->getType($call->var);
        if (!new ObjectType(self::QUERY)->isSuperTypeOf($type)->yes()) {
            return [];
        }
        $names = $type->getTemplateType(self::QUERY, 'T')->getObjectClassNames();
        if (count($names) !== 1 || !class_exists($names[0])) {
            return [];
        }
        $required = EntityMap::of($names[0])->requiredScopes;
        if ($required === []) {
            return [];
        }
        // Follow the chain back to Entities::query(); give up when it starts elsewhere.
        $calls = [];
        $expr = $call->var;
        while ($expr instanceof MethodCall) {
            $calls[] = $expr;
            $expr = $expr->var;
        }
        if (
            !$expr instanceof StaticCall
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || !in_array($scope->resolveName($expr->class), [self::ENTITIES, self::QUERY], true)
            || !in_array(strtolower($expr->name->toString()), ['query', 'of'], true)
        ) {
            return [];
        }
        $errors = [];
        foreach ($required as $class) {
            if (!$this->chainHas($calls, $class, $scope)) {
                $errors[] = self::error(
                    $names[0],
                    $class,
                    sprintf('pass ->scoped(new %s(...)) or ->withoutScope(%s::class) in the query', self::short($class), self::short($class)),
                );
            }
        }

        return $errors;
    }

    /**
     * @param list<MethodCall> $calls
     * @param class-string $required
     */
    private function chainHas(array $calls, string $required, Scope $scope): bool
    {
        foreach ($calls as $call) {
            $name = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
            if ($name === null) {
                // A dynamic method name could be anything.
                return true;
            }
            if ($name === 'scoped' && $this->argsMayHold($call->getArgs(), $required, $scope)) {
                return true;
            }
            if ($name === 'withoutscope') {
                foreach ($call->getArgs() as $arg) {
                    $strings = $scope->getType($arg->value)->getConstantStrings();
                    if ($strings === [] || in_array($required, array_map(static fn ($string): string => ltrim($string->getValue(), '\\'), $strings), true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether scoped(...$args) may hold an instance of $required: certainly when an argument is
     * one, and also when the type is too vague to tell.
     *
     * @param array<Arg> $args
     * @param class-string $required
     */
    private function argsMayHold(array $args, string $required, Scope $scope): bool
    {
        foreach ($args as $arg) {
            $type = $scope->getType($arg->value);
            if ($arg->unpack ? $this->listMayHold($type, $required) : $this->mayBe($type, $required)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $required
     */
    private function listMayHold(Type $type, string $required): bool
    {
        $arrays = $type->getConstantArrays();
        if ($arrays === []) {
            return $type->isIterable()->no() ? false : $this->mayBe($type->getIterableValueType(), $required);
        }
        foreach ($arrays as $array) {
            foreach ($array->getValueTypes() as $value) {
                if ($this->mayBe($value, $required)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param class-string $required
     */
    private function mayBe(Type $type, string $required): bool
    {
        $result = new ObjectType($required)->isSuperTypeOf($type);

        // "maybe" covers the Scope interface, mixed and unions with the scope.
        return !$result->no();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function entities(StaticCall $call, Scope $scope): array
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier || $scope->resolveName($call->class) !== self::ENTITIES) {
            return [];
        }
        $method = strtolower($call->name->toString());
        if (!isset(self::STATIC[$method])) {
            return [];
        }
        [$position, $given] = self::STATIC[$method];
        $args = $call->getArgs();
        $first = $args[0] ?? null;
        if ($first === null || $first->name !== null) {
            return [];
        }
        $firstType = $scope->getType($first->value);
        $entity = match ($given) {
            'class' => self::single(array_map(static fn ($string): string => $string->getValue(), $firstType->getConstantStrings())),
            'object' => self::single($firstType->getObjectClassNames()),
            'list', 'relation' => self::single($firstType->getIterableValueType()->getObjectClassNames()),
        };
        if ($entity === null || !class_exists($entity)) {
            return [];
        }
        if ($given === 'relation') {
            $relation = self::single(array_map(
                static fn ($string): string => $string->getValue(),
                isset($args[1]) ? $scope->getType($args[1]->value)->getConstantStrings() : [],
            ));
            if ($relation === null) {
                return [];
            }
            $entity = EntityMap::of($entity)->relation($relation)->entity;
            if (!class_exists($entity)) {
                return [];
            }
        }
        $required = EntityMap::of($entity)->requiredScopes;
        if ($required === []) {
            return [];
        }
        $scopes = null;
        foreach ($args as $index => $arg) {
            if ($arg->name !== null && $arg->name->toString() === 'scopes' || $arg->name === null && $index === $position) {
                $scopes = $arg;
            }
            if ($arg->unpack) {
                // Arguments spread from an array could be anything.
                return [];
            }
        }
        $errors = [];
        foreach ($required as $class) {
            if ($scopes === null || !$this->listMayHold($scope->getType($scopes->value), $class)) {
                $errors[] = self::error(
                    $entity,
                    $class,
                    sprintf('pass it in $scopes: Entities::%s(..., scopes: [new %s(...)])', $call->name->toString(), self::short($class)),
                );
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $names
     */
    private static function single(array $names): ?string
    {
        return count($names) === 1 ? $names[0] : null;
    }

    private static function short(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private static function error(string $entity, string $scope, string $fix): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf('%s requires the scope %s (#[RequiredScope]); %s.', $entity, $scope, $fix))
            ->identifier('shirakaba.requiredScope')
            ->build();
    }
}
