<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Mapping;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\Column;
use Shirakaba\Orm\Attribute\CreatedAt;
use Shirakaba\Orm\Attribute\DefaultScope;
use Shirakaba\Orm\Attribute\Embedded;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\HasOne;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Json;
use Shirakaba\Orm\Attribute\ManyToMany;
use Shirakaba\Orm\Attribute\RequiredScope;
use Shirakaba\Orm\Attribute\SoftDelete;
use Shirakaba\Orm\Attribute\Table;
use Shirakaba\Orm\Attribute\UpdatedAt;
use Shirakaba\Orm\Attribute\Version;
use Shirakaba\Orm\ColumnType;
use Shirakaba\Orm\MappingError;
use Shirakaba\Orm\NotDeleted;
use Shirakaba\Orm\Scope;
use Shirakaba\Query\Values;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use UnexpectedValueException;

/**
 * How one entity class maps to its table. Built from attributes; the same plan
 * hydrates every row of a query.
 *
 * @internal
 * @template-covariant T of object
 */
final readonly class EntityMap
{
    /**
     * @param class-string<T> $class
     * @param list<Field> $fields
     * @param array<string, Relation> $relations
     * @param list<class-string<Scope>> $defaultScopes
     * @param list<class-string<Scope>> $requiredScopes
     */
    private function __construct(
        public string $class,
        public string $table,
        public array $fields,
        public array $relations,
        public array $defaultScopes = [],
        public array $requiredScopes = [],
        public ?string $softDelete = null,
        public ?string $label = null,
    ) {}

    /**
     * @template C of object
     * @param class-string<C> $class
     * @return self<C>
     * @throws MappingError when the class is not a valid entity
     */
    public static function of(string $class): self
    {
        if (!class_exists($class)) {
            throw new MappingError(sprintf('%s is not a class.', $class));
        }
        $reflection = new ReflectionClass($class);
        $table = ($reflection->getAttributes(Table::class)[0] ?? null)?->newInstance();
        if ($table === null) {
            throw new MappingError(sprintf('%s is not an entity: add #[Table(\'...\')].', $class));
        }
        $fields = self::fields($reflection, '');
        if (!array_any($fields, static fn (Field $field): bool => $field->id)) {
            throw new MappingError(sprintf('%s needs at least one #[Id] property.', $class));
        }

        $relations = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $declared = $attribute->newInstance();
            $relation = match (true) {
                $declared instanceof BelongsTo => new Relation($declared->name, 'belongsTo', $declared->entity, $declared->foreignKey),
                $declared instanceof HasOne => new Relation($declared->name, 'hasOne', $declared->entity, $declared->foreignKey),
                $declared instanceof HasMany => new Relation($declared->name, 'hasMany', $declared->entity, $declared->foreignKey, orderBy: $declared->orderBy),
                $declared instanceof ManyToMany => new Relation($declared->name, 'manyToMany', $declared->entity, $declared->foreignKey, $declared->through, $declared->localKey, orderBy: $declared->orderBy),
                default => null,
            };
            if ($relation === null) {
                continue;
            }
            if (isset($relations[$relation->name])) {
                throw new MappingError(sprintf('%s declares the relation "%s" twice.', $class, $relation->name));
            }
            $relations[$relation->name] = $relation;
        }

        $defaults = [];
        $required = [];
        foreach ($reflection->getAttributes(DefaultScope::class) as $attribute) {
            $defaults[] = self::scope($class, $attribute->newInstance()->scope, needsNoArguments: true);
        }
        foreach ($reflection->getAttributes(RequiredScope::class) as $attribute) {
            $required[] = self::scope($class, $attribute->newInstance()->scope, needsNoArguments: false);
        }
        $soft = ($reflection->getAttributes(SoftDelete::class)[0] ?? null)?->newInstance();
        if ($soft !== null) {
            $field = null;
            foreach ($fields as $candidate) {
                if ($candidate->property === $soft->property) {
                    $field = $candidate;
                }
            }
            if ($field === null || $field->type !== DateTimeImmutable::class || !$field->nullable) {
                throw new MappingError(sprintf('%s is #[SoftDelete], so $%s must be a nullable DateTimeImmutable property.', $class, $soft->property));
            }
            $defaults[] = NotDeleted::class;
        }

        return new self($class, $table->name, $fields, $relations, $defaults, $required, $soft?->property, $table->label);
    }

    /**
     * @return class-string<Scope>
     */
    private static function scope(string $entity, string $scope, bool $needsNoArguments): string
    {
        if (!is_a($scope, Scope::class, true)) {
            throw new MappingError(sprintf('%s: %s is not a Scope.', $entity, $scope));
        }
        if ($needsNoArguments && (new ReflectionClass($scope)->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new MappingError(sprintf('%s: the default scope %s must have a constructor without required arguments; use #[RequiredScope] for scopes that need values.', $entity, $scope));
        }

        return $scope;
    }

    /**
     * Whether the entity declares the scope class.
     */
    public function declaresScope(string $scope): bool
    {
        return in_array($scope, $this->defaultScopes, true) || in_array($scope, $this->requiredScopes, true);
    }

    public function field(string $property): Field
    {
        $path = explode('.', $property);
        $fields = $this->fields;
        $found = null;
        foreach ($path as $segment) {
            $found = null;
            foreach ($fields as $field) {
                if ($field->property === $segment) {
                    $found = $field;
                    break;
                }
            }
            if ($found === null) {
                break;
            }
            $fields = $found->children;
        }
        if ($found === null || $found->kind === 'embedded') {
            throw new MappingError(sprintf('%s has no stored property "%s".', $this->class, $property));
        }

        return $found;
    }

    public function relation(string $name): Relation
    {
        return $this->relations[$name] ?? throw new MappingError(sprintf('%s has no relation "%s"; relations are declared on the class with #[BelongsTo], #[HasOne], #[HasMany] or #[ManyToMany].', $this->class, $name) . \Shirakaba\Orm\Internal\Messages::see('unknown-relation'));
    }

    /**
     * @return list<Field> the primary key fields
     */
    public function ids(): array
    {
        return array_values(array_filter($this->fields, static fn (Field $field): bool => $field->id));
    }

    /**
     * @return list<string> every column, in field order
     */
    public function columns(): array
    {
        $columns = [];
        foreach ($this->fields as $field) {
            foreach ($field->columns() as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     * @throws UnexpectedValueException when a column is missing or holds an invalid value
     */
    public function hydrate(array $row, Dialect $dialect): object
    {
        return new ($this->class)(...self::arguments($this->class, $this->fields, $row, $dialect));
    }

    /**
     * The column values of an entity, for writes.
     *
     * @return array<string, mixed> column => bindable value
     */
    public function dehydrate(object $entity): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            self::write($field, self::property($entity, $field->property), $values);
        }

        return $values;
    }

    /**
     * A property value converted for a WHERE clause or a write.
     */
    public function databaseValue(Field $field, mixed $value): mixed
    {
        $values = [];
        self::write($field, $value, $values);

        return $values[$field->column] ?? null;
    }

    public static function property(object $entity, string $property): mixed
    {
        return new \ReflectionProperty($entity, $property)->getValue($entity);
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<Field>
     */
    private static function fields(ReflectionClass $class, string $prefix): array
    {
        $fields = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $fields[] = self::field_($class->getName(), $parameter, $prefix);
        }
        if ($fields === []) {
            throw new MappingError(sprintf('%s has no constructor parameters to map.', $class->getName()));
        }

        return $fields;
    }

    /**
     * @param class-string $class
     */
    private static function field_(string $class, ReflectionParameter $parameter, string $prefix): Field
    {
        $name = $parameter->getName();
        $where = sprintf('%s::__construct() parameter $%s', $class, $name);
        $property = new \ReflectionProperty($class, $name);
        if (!$parameter->isPromoted() || !$property->isPublic()) {
            throw new MappingError($where . ' must be a promoted public property, so writes can read it.');
        }
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new MappingError($where . ' needs a single declared type, optionally nullable.');
        }
        $typeName = $type->getName();
        $attributes = static fn (string $attribute): bool => $parameter->getAttributes($attribute) !== [];
        $columnAttribute = ($parameter->getAttributes(Column::class)[0] ?? null)?->newInstance();
        $column = $prefix . ($columnAttribute->name ?? self::snake($name));
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->getDefaultValue() : null;
        $flags = [
            'id' => $attributes(Id::class),
            'generated' => $attributes(Generated::class),
            'version' => $attributes(Version::class),
            'createdAt' => $attributes(CreatedAt::class),
            'updatedAt' => $attributes(UpdatedAt::class),
        ];
        if ($flags['generated'] && (!$type->allowsNull() || !$hasDefault || $default !== null)) {
            throw new MappingError($where . ' is #[Generated], so it must be nullable with the default null.');
        }
        if ($flags['version'] && $typeName !== 'int') {
            throw new MappingError($where . ' is a #[Version], so it must be an int.');
        }
        if (($flags['createdAt'] || $flags['updatedAt']) && $typeName !== DateTimeImmutable::class) {
            throw new MappingError($where . ' is a timestamp, so it must be a DateTimeImmutable.');
        }
        if (is_a($typeName, DateTimeInterface::class, true) && $typeName !== DateTimeImmutable::class) {
            throw new MappingError($where . ' must be DateTimeImmutable (design/07-date-and-time.md).');
        }

        $common = [$name, $column];
        $tail = [$type->allowsNull(), $hasDefault, $default, $flags['id'], $flags['generated'], $flags['version'], $flags['createdAt'], $flags['updatedAt']];

        if ($columnAttribute?->type !== null) {
            $converter = $columnAttribute->type;
            if (!is_a($converter, ColumnType::class, true)) {
                throw new MappingError(sprintf('%s: %s does not implement ColumnType.', $where, $converter));
            }

            return new Field(...$common, kind: 'custom', type: $typeName, nullable: $tail[0], hasDefault: $tail[1], default: $tail[2], id: $tail[3], generated: $tail[4], converter: new $converter());
        }
        if ($attributes(Json::class)) {
            if ($typeName !== 'array' && !class_exists($typeName) && !interface_exists($typeName)) {
                throw new MappingError($where . ' is #[Json], so it must be an array or a DTO class.');
            }
            if ($typeName !== 'array') {
                if (!class_exists(\Shirakaba\Json\Json::class)) {
                    // The ORM works without the framework; DTO columns need shirakaba/json (design/08-database.md).
                    throw new MappingError($where . ' is #[Json] with a DTO type, which needs shirakaba/json: composer require shirakaba/json.');
                }
                // Fail at planning, not on the first row, when the DTO cannot be mapped.
                try {
                    new \Shirakaba\Json\Internal\Planner(false, $where)->root($typeName);
                } catch (\Shirakaba\Core\InvalidApplication $e) {
                    throw new MappingError($e->getMessage(), 0, $e);
                }
            }

            return new Field(...$common, kind: 'json', type: $typeName, nullable: $tail[0], hasDefault: $tail[1], default: $tail[2]);
        }
        $embedded = ($parameter->getAttributes(Embedded::class)[0] ?? null)?->newInstance();
        if ($embedded !== null) {
            if (!class_exists($typeName)) {
                throw new MappingError($where . ' is #[Embedded], so its type must be a class.');
            }

            return new Field(
                $name,
                $column,
                'embedded',
                $typeName,
                $type->allowsNull(),
                $hasDefault,
                $default,
                children: self::fields(new ReflectionClass($typeName), $prefix . ($embedded->prefix ?? self::snake($name) . '_')),
            );
        }
        if (Values::supports($typeName) && $typeName !== 'mixed') {
            return new Field(...$common, kind: 'value', type: $typeName, nullable: $tail[0], hasDefault: $tail[1], default: $tail[2], id: $tail[3], generated: $tail[4], version: $tail[5], createdAt: $tail[6], updatedAt: $tail[7]);
        }
        if (class_exists($typeName)) {
            $wrapped = self::wrapper($typeName);
            if ($wrapped !== null) {
                return new Field(...$common, kind: 'wrapper', type: $typeName, nullable: $tail[0], hasDefault: $tail[1], default: $tail[2], id: $tail[3], generated: $tail[4], innerType: $wrapped[1], wrapperProperty: $wrapped[0]);
            }
        }

        throw new MappingError(sprintf(
            '%s has the unsupported type %s; use int, float, bool, string, a backed enum, DateTimeImmutable, LocalDate, a single-value class, #[Embedded], #[Json] or #[Column(type: ...)].',
            $where,
            $typeName,
        ));
    }

    /**
     * A class wrapping one scalar, such as `TaskId(public int $value)`.
     *
     * @return array{string, string}|null property name and scalar type
     */
    private static function wrapper(string $class): ?array
    {
        if (!class_exists($class)) {
            return null;
        }
        $parameters = new ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
        $only = $parameters[0] ?? null;
        if (count($parameters) !== 1 || $only === null || !$only->isPromoted()) {
            return null;
        }
        $type = $only->getType();
        if (!$type instanceof ReflectionNamedType || $type->allowsNull() || !in_array($type->getName(), ['int', 'string', 'float', 'bool'], true)) {
            return null;
        }

        return new \ReflectionProperty($class, $only->getName())->isPublic() ? [$only->getName(), $type->getName()] : null;
    }

    /**
     * @param class-string $class
     * @param list<Field> $fields
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function arguments(string $class, array $fields, array $row, Dialect $dialect): array
    {
        $arguments = [];
        foreach ($fields as $field) {
            if ($field->kind === 'embedded') {
                $columns = $field->columns();
                $allNull = array_all($columns, static fn (string $column): bool => ($row[$column] ?? null) === null);
                if ($allNull && $field->nullable) {
                    $arguments[$field->property] = null;
                    continue;
                }
                /** @var class-string $embedded */
                $embedded = $field->type;
                $arguments[$field->property] = new $embedded(...self::arguments($embedded, $field->children, $row, $dialect));
                continue;
            }
            if (!array_key_exists($field->column, $row)) {
                if ($field->hasDefault) {
                    $arguments[$field->property] = $field->default;
                    continue;
                }
                throw new UnexpectedValueException(sprintf('The result has no column "%s" for %s::$%s.', $field->column, $class, $field->property));
            }
            $arguments[$field->property] = self::read($class, $field, $row[$field->column], $dialect);
        }

        return $arguments;
    }

    private static function read(string $class, Field $field, mixed $value, Dialect $dialect): mixed
    {
        if ($value === null) {
            if (!$field->nullable) {
                throw new UnexpectedValueException(sprintf('Column "%s" is NULL, but %s::$%s is not nullable.', $field->column, $class, $field->property));
            }

            return null;
        }
        $converted = match ($field->kind) {
            'value' => Values::read($value, $field->type, $dialect),
            'wrapper' => ($inner = Values::read($value, $field->innerType ?? 'mixed', $dialect)) === null ? null : new ($field->type)($inner),
            'custom' => $field->converter?->fromDatabase($value),
            'json' => $field->type === 'array'
                ? (is_string($value) ? self::decode($value) : (is_array($value) ? $value : null))
                : (is_string($value) && (class_exists($field->type) || interface_exists($field->type)) ? \Shirakaba\Json\JsonMapper::decode($value, $field->type) : null),
            'embedded' => null,
        };
        if ($converted === null) {
            throw new UnexpectedValueException(sprintf('Column "%s" holds %s, which is not a valid %s for %s::$%s.', $field->column, get_debug_type($value), $field->type, $class, $field->property));
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function write(Field $field, mixed $value, array &$values): void
    {
        if ($field->kind === 'embedded') {
            foreach ($field->children as $child) {
                self::write($child, is_object($value) ? self::property($value, $child->property) : null, $values);
            }

            return;
        }
        $values[$field->column] = match (true) {
            $value === null => null,
            $field->kind === 'wrapper' && is_object($value) => self::property($value, $field->wrapperProperty ?? 'value'),
            $field->kind === 'custom' => $field->converter?->toDatabase($value),
            $field->kind === 'json' => self::encode($value),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }

    /**
     * Encodes a #[Json] value: with shirakaba/json's rules when it is installed (DTOs,
     * instants, LocalDate), otherwise with PHP's encoder and the same flags (design/08-database.md).
     *
     * @throws JsonException when the value cannot be encoded
     * @throws \UnexpectedValueException when the value holds an object shirakaba/json cannot encode
     */
    private static function encode(mixed $value): string
    {
        if (class_exists(\Shirakaba\Json\Json::class)) {
            return \Shirakaba\Json\Json::encode($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
    }
}
