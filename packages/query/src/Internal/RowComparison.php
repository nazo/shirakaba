<?php

declare(strict_types=1);

namespace Shirakaba\Query\Internal;

use Shirakaba\Query\Sql;

/**
 * `(a, b) > (?, ?)`, for keyset pagination. MySQL, PostgreSQL and SQLite all compare row
 * values lexicographically.
 *
 * @internal
 */
final readonly class RowComparison implements Condition
{
    /**
     * @param non-empty-list<string|Sql> $columns
     * @param non-empty-list<mixed> $values
     * @param '<'|'>' $operator
     */
    public function __construct(
        private array $columns,
        private array $values,
        private string $operator,
    ) {}

    public function compile(Output $out): string
    {
        $columns = array_map(static fn (string|Sql $column): string => $column instanceof Sql ? $out->raw($column) : $out->identifier($column), $this->columns);
        $values = array_map($out->value(...), $this->values);
        if (count($columns) === 1) {
            return sprintf('%s %s %s', $columns[0], $this->operator, $values[0]);
        }

        return sprintf('(%s) %s (%s)', implode(', ', $columns), $this->operator, implode(', ', $values));
    }
}
