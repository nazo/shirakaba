<?php

declare(strict_types=1);

namespace Shirakaba\Query;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Shirakaba\Database\Dialect\Dialect;
use UnexpectedValueException;

/**
 * Maps result rows into objects through their constructor. Each parameter is
 * filled from the column of the same name, or its snake_case form ($createdAt from
 * "created_at"), converted to the parameter's type:
 *
 * - int, float, bool and string from whatever the driver returned (numeric strings,
 *   0/1 booleans);
 * - DateTimeImmutable through the dialect, in UTC; LocalDate from "YYYY-MM-DD";
 * - backed enums from their value;
 * - null only for nullable parameters; a missing column uses the parameter's default.
 *
 * The plan is built once per class and reused for every row.
 *
 * @template T of object
 */
final readonly class RowMapper
{
    /**
     * @param class-string<T> $class
     * @param list<array{string, non-empty-list<string>, string, bool, bool, mixed}> $fields
     *        parameter, candidate columns, type, nullable, has default, default
     */
    private function __construct(
        private string $class,
        private array $fields,
        private Dialect $dialect,
    ) {}

    /**
     * @template C of object
     * @param class-string<C> $class
     * @param array<string, string> $columns parameter name => column name, overriding the naming rule
     * @return self<C>
     * @throws InvalidArgumentException when a constructor parameter has an unsupported type
     */
    public static function for(string $class, Dialect $dialect, array $columns = []): self
    {
        $fields = [];
        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();
            $where = sprintf('%s::__construct() parameter $%s', $class, $name);
            if (!$type instanceof ReflectionNamedType) {
                throw new InvalidArgumentException($where . ' needs a single declared type.');
            }
            $typeName = $type->getName();
            if (!Values::supports($typeName)) {
                throw new InvalidArgumentException(sprintf('%s has the unsupported type %s for row mapping.', $where, $typeName));
            }
            $candidates = isset($columns[$name]) ? [$columns[$name]] : array_values(array_unique([$name, self::snake($name)]));
            $hasDefault = $parameter->isDefaultValueAvailable();
            $fields[] = [$name, $candidates, $typeName, $type->allowsNull(), $hasDefault, $hasDefault ? $parameter->getDefaultValue() : null];
        }

        return new self($class, $fields, $dialect);
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     * @throws UnexpectedValueException when a column is missing or has a value of the wrong type
     */
    public function map(array $row): object
    {
        $arguments = [];
        foreach ($this->fields as [$name, $candidates, $type, $nullable, $hasDefault, $default]) {
            $found = false;
            $value = null;
            foreach ($candidates as $column) {
                if (array_key_exists($column, $row)) {
                    $found = true;
                    $value = $row[$column];
                    break;
                }
            }
            if (!$found) {
                if (!$hasDefault) {
                    throw new UnexpectedValueException(sprintf('The result has no column "%s" for %s::$%s.', $candidates[0], $this->class, $name));
                }
                $arguments[$name] = $default;
                continue;
            }
            $arguments[$name] = $this->convert($value, $type, $nullable, $name);
        }

        return new $this->class(...$arguments);
    }

    private function convert(mixed $value, string $type, bool $nullable, string $name): mixed
    {
        if ($value === null || $type === 'mixed') {
            if ($value === null && !$nullable && $type !== 'mixed') {
                throw new UnexpectedValueException(sprintf('Column for %s::$%s is NULL, but the parameter is not nullable.', $this->class, $name));
            }

            return $value;
        }

        $converted = Values::read($value, $type, $this->dialect);
        if ($converted === null) {
            throw new UnexpectedValueException(sprintf(
                'Column for %s::$%s holds %s, which is not a valid %s.',
                $this->class,
                $name,
                get_debug_type($value),
                $type,
            ));
        }

        return $converted;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
    }
}
