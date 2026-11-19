<?php

declare(strict_types=1);

namespace Shirakaba\Query\Internal;

use InvalidArgumentException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Query\Compiled;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;

/**
 * Collects parameters in the order their placeholders appear while a query is compiled.
 *
 * @internal
 */
final class Output
{
    /** @var list<scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> */
    private array $parameters = [];

    public function __construct(
        public readonly Dialect $dialect,
    ) {}

    /**
     * Quotes a validated identifier: "name", "table.name", "table.*" or "*".
     *
     * @throws InvalidArgumentException for anything else, so names from user input cannot inject SQL
     */
    public function identifier(string $name): string
    {
        if (preg_match('/^(?:\*|[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))?)$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid column or table name; use Sql::raw() for expressions.', $name));
        }

        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * A placeholder for a value, or the compiled form of a raw fragment or subquery.
     */
    public function value(mixed $value): string
    {
        if ($value instanceof Sql) {
            return $this->raw($value);
        }
        if ($value instanceof Select) {
            return '(' . $value->compileInto($this) . ')';
        }
        if (!self::bindable($value)) {
            throw new InvalidArgumentException(sprintf('Unable to bind a %s value.', get_debug_type($value)));
        }
        /** @var scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null $value */
        $this->parameters[] = $value;

        return '?';
    }

    public function raw(Sql $sql): string
    {
        foreach ($sql->parameters as $parameter) {
            $this->parameters[] = $parameter;
        }

        return $sql->sql;
    }

    public function result(string $sql): Compiled
    {
        return new Compiled($sql, $this->parameters);
    }

    private static function bindable(mixed $value): bool
    {
        return (
            $value === null
                || is_scalar($value)
                || $value instanceof \DateTimeImmutable
                || $value instanceof \Shirakaba\Time\LocalDate
                || $value instanceof \BackedEnum
        );
    }
}
