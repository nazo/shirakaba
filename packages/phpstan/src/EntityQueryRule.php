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
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Projection;
use Throwable;

/**
 * Checks ORM queries against entity mappings: property and relation names in
 * EntityQuery calls and Entities::patch(), the types of compared and patched values, and
 * projections against the relations requested in the same call chain (or, for
 * Entities::into(), against the entity alone). Names are only
 * checked when they are constant strings, given directly or spread from a constant array;
 * a projection is not checked when some relation in its chain is not constant.
 *
 * Mappings are read with the ORM itself, so analysed entity classes must be autoloadable.
 * Without shirakaba/orm installed the rule does nothing.
 *
 * @implements Rule<Expr>
 */
final readonly class EntityQueryRule implements Rule
{
    private const string QUERY = 'Shirakaba\Orm\EntityQuery';
    private const string CONDITIONS = 'Shirakaba\Orm\PropertyConditions';
    private const string ENTITIES = 'Shirakaba\Orm\Entities';

    /** @var list<string> Methods whose first argument is a property name. */
    private const array PROPERTY_METHODS = [
        'where',
        'wherein',
        'wherenotin',
        'wherenull',
        'wherenotnull',
        'wherebetween',
        'orderby',
        'orwhere',
        'orwherein',
        'orwherenotin',
        'orwherenull',
    ];

    /** @var list<string> Methods whose first argument is a relation name. */
    private const array RELATION_METHODS = ['wherehas', 'wheredoesnthave'];

    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

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
                $node instanceof MethodCall => $this->methodCall($node, $scope),
                $node instanceof StaticCall => $this->staticCall($node, $scope),
                default => [],
            };
        } catch (Throwable $e) {
            return [self::error(sprintf('Unable to check the ORM call: %s', $e->getMessage()))];
        }
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function methodCall(MethodCall $call, Scope $scope): array
    {
        if (!$call->name instanceof Identifier) {
            return [];
        }
        $entity = $this->entity($scope->getType($call->var));
        if ($entity === null || !class_exists($entity)) {
            // Classes that are not autoloadable cannot be mapped here; PHPStan reports unknown classes itself.
            return [];
        }
        $method = strtolower($call->name->toString());
        $map = $this->map($entity);
        if ($map instanceof IdentifierRuleError) {
            return [$map];
        }
        $args = $call->getArgs();

        if (in_array($method, self::PROPERTY_METHODS, true)) {
            $property = self::string($args[0] ?? null, $scope);
            if ($property === null) {
                return [];
            }
            $error = $this->property($map, $entity, $property);
            if ($error !== null) {
                return [$error];
            }
            if (($method === 'where' || $method === 'orwhere') && isset($args[2])) {
                return $this->valueType($entity, $property, $scope->getType($args[2]->value));
            }

            return [];
        }
        if ($method === 'after') {
            $errors = [];
            foreach ($scope->getType(isset($args[0]) ? $args[0]->value : $call)->getConstantArrays() as $array) {
                foreach ($array->getKeyTypes() as $key) {
                    foreach ($key->getConstantStrings() as $name) {
                        $error = $this->property($map, $entity, $name->getValue());
                        if ($error !== null) {
                            $errors[] = $error;
                        }
                    }
                }
            }

            return $errors;
        }
        if (in_array($method, self::RELATION_METHODS, true) || $method === 'with' || $method === 'withwhere' || $method === 'withcount') {
            $errors = [];
            [$paths] = self::names($method === 'with' || $method === 'withcount' ? $args : array_slice($args, 0, 1), $scope);
            foreach ($paths as $path) {
                $error = $this->relationPath($entity, $path);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }

            return $errors;
        }
        if ($method === 'into') {
            $projection = self::string($args[0] ?? null, $scope);
            if ($projection === null || !class_exists($projection)) {
                return [];
            }
            $requested = self::tree($call->var, $scope);
            if ($requested === null) {
                // Some relation names are only known at run time.
                return [];
            }
            [$tree, $counts] = $requested;
            foreach (self::paths($tree) as $path) {
                if ($this->relationPath($entity, $path) !== null) {
                    // with() already reports the unknown relation.
                    return [];
                }
            }
            try {
                /** @var class-string $projection */
                Projection::plan($projection, $map, $tree, $counts);
            } catch (Throwable $e) {
                return [self::error($e->getMessage())];
            }
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function staticCall(StaticCall $call, Scope $scope): array
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier || $scope->resolveName($call->class) !== self::ENTITIES) {
            return [];
        }
        $method = strtolower($call->name->toString());
        $args = $call->getArgs();
        if ($method === 'into') {
            return $this->into($args, $scope);
        }
        if ($method !== 'patch') {
            return [];
        }
        $entity = self::string($args[0] ?? null, $scope);
        $changes = $args[2] ?? null;
        if ($entity === null || $changes === null || !class_exists($entity)) {
            return [];
        }
        $map = $this->map($entity);
        if ($map instanceof IdentifierRuleError) {
            return [$map];
        }
        $errors = [];
        foreach ($scope->getType($changes->value)->getConstantArrays() as $array) {
            foreach ($array->getKeyTypes() as $index => $key) {
                foreach ($key->getConstantStrings() as $name) {
                    $error = $this->property($map, $entity, $name->getValue());
                    if ($error !== null) {
                        $errors[] = $error;
                        continue;
                    }
                    $errors = [...$errors, ...$this->valueType($entity, $name->getValue(), $array->getValueTypes()[$index] ?? $key)];
                }
            }
        }

        return $errors;
    }

    /**
     * Entities::into($entity, View::class): the projection must fit the entity alone.
     *
     * @param array<Arg> $args
     * @return list<IdentifierRuleError>
     */
    private function into(array $args, Scope $scope): array
    {
        $projection = self::string($args[1] ?? null, $scope);
        $entities = isset($args[0]) ? $scope->getType($args[0]->value)->getObjectClassNames() : [];
        if ($projection === null || !class_exists($projection) || count($entities) !== 1 || !class_exists($entities[0])) {
            return [];
        }
        $map = $this->map($entities[0]);
        if ($map instanceof IdentifierRuleError) {
            return [$map];
        }
        try {
            /** @var class-string $projection */
            Projection::plan($projection, $map, []);
        } catch (Throwable $e) {
            return [self::error($e->getMessage())];
        }

        return [];
    }

    /**
     * The entity of an EntityQuery<T>, or of the PropertyConditions<T> a whereGroup() closure
     * receives.
     */
    private function entity(Type $type): ?string
    {
        foreach ([self::QUERY, self::CONDITIONS] as $class) {
            if (new ObjectType($class)->isSuperTypeOf($type)->yes()) {
                $names = $type->getTemplateType($class, 'T')->getObjectClassNames();

                return count($names) === 1 ? $names[0] : null;
            }
        }

        return null;
    }

    /**
     * @return EntityMap<object>|IdentifierRuleError
     */
    private function map(string $class): EntityMap|IdentifierRuleError
    {
        try {
            /** @var class-string $class */
            return EntityMap::of($class);
        } catch (Throwable $e) {
            return self::error($e->getMessage());
        }
    }

    /**
     * @param EntityMap<object> $map
     */
    private function property(EntityMap $map, string $entity, string $property): ?IdentifierRuleError
    {
        try {
            $map->field($property);

            return null;
        } catch (Throwable) {
            return self::error(sprintf('%s has no stored property "%s".', $entity, $property));
        }
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function valueType(string $entity, string $property, Type $given): array
    {
        if (str_contains($property, '.') || !$this->reflection->hasClass($entity)) {
            return [];
        }
        foreach (['Shirakaba\Query\Sql', 'Shirakaba\Query\Select'] as $expression) {
            if (!new ObjectType($expression)->isSuperTypeOf($given)->no()) {
                return [];
            }
        }
        $class = $this->reflection->getClass($entity);
        if (!$class->hasNativeProperty($property)) {
            return [];
        }
        $expected = $class->getNativeProperty($property)->getReadableType();
        if (!$expected->isSuperTypeOf($given)->no()) {
            return [];
        }

        return [self::error(sprintf(
            '%s::$%s is %s, but the value is %s.',
            $entity,
            $property,
            $expected->describe(VerbosityLevel::typeOnly()),
            $given->describe(VerbosityLevel::typeOnly()),
        ))];
    }

    private function relationPath(string $entity, string $path): ?IdentifierRuleError
    {
        try {
            /** @var class-string $class */
            $class = $entity;
            foreach (explode('.', $path) as $segment) {
                $class = EntityMap::of($class)->relation($segment)->entity;
            }

            return null;
        } catch (Throwable $e) {
            return self::error($e->getMessage());
        }
    }

    /**
     * The relations requested with with(), withWhere() and withCount() earlier in the same
     * call chain, or null when some of them are not constant.
     *
     * @return array{array<string, array<string, mixed>>, list<string>}|null
     */
    private static function tree(Expr $chain, Scope $scope): ?array
    {
        $tree = [];
        $counts = [];
        while ($chain instanceof MethodCall) {
            $name = $chain->name instanceof Identifier ? strtolower($chain->name->toString()) : '';
            $args = match ($name) {
                'with', 'withcount' => $chain->getArgs(),
                'withwhere' => array_slice($chain->getArgs(), 0, 1),
                default => [],
            };
            [$paths, $complete] = self::names($args, $scope);
            if (!$complete) {
                return null;
            }
            foreach ($paths as $path) {
                if ($name === 'withcount') {
                    $counts[] = $path;
                } else {
                    $tree = self::insert($tree, explode('.', $path));
                }
            }
            $chain = $chain->var;
        }

        return [$tree, $counts];
    }

    /**
     * @param array<string, array<string, mixed>> $tree
     * @param list<string> $segments
     * @return array<string, array<string, mixed>>
     */
    private static function insert(array $tree, array $segments): array
    {
        $first = array_shift($segments);
        if ($first === null) {
            return $tree;
        }
        $children = $tree[$first] ?? [];
        /** @var array<string, array<string, mixed>> $children */
        $tree[$first] = self::insert($children, $segments);

        return $tree;
    }

    /**
     * @param array<array-key, mixed> $tree
     * @return list<string>
     */
    private static function paths(array $tree, string $prefix = ''): array
    {
        $paths = [];
        foreach ($tree as $name => $children) {
            $paths[] = $prefix . $name;
            if (is_array($children)) {
                foreach (self::paths($children, $prefix . $name . '.') as $path) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * The constant strings among $args, including those spread from a constant array, such
     * as with(...self::RELATIONS).
     *
     * @param array<Arg> $args
     * @return array{list<string>, bool} the names, and whether every argument was constant
     */
    private static function names(array $args, Scope $scope): array
    {
        $names = [];
        $complete = true;
        foreach ($args as $arg) {
            if (!$arg->unpack) {
                $name = self::string($arg, $scope);
                if ($name === null) {
                    $complete = false;
                } else {
                    $names[] = $name;
                }
                continue;
            }
            $arrays = $scope->getType($arg->value)->getConstantArrays();
            if (count($arrays) !== 1 || $arrays[0]->getOptionalKeys() !== []) {
                $complete = false;
                continue;
            }
            foreach ($arrays[0]->getValueTypes() as $value) {
                $strings = $value->getConstantStrings();
                if (count($strings) === 1) {
                    $names[] = $strings[0]->getValue();
                } else {
                    $complete = false;
                }
            }
        }

        return [$names, $complete];
    }

    private static function string(?Arg $arg, Scope $scope): ?string
    {
        if ($arg === null) {
            return null;
        }
        $type = $scope->getType($arg->value);
        $strings = $type->getConstantStrings();
        if (count($strings) !== 1) {
            return null;
        }

        return $strings[0]->getValue();
    }

    private static function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('shirakaba.orm')->build();
    }
}
