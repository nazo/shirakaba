<?php

declare(strict_types=1);

namespace Shirakaba\Query\Internal;

use InvalidArgumentException;
use Shirakaba\Query\Sql;

/**
 * A comparison of a column with a value, a subquery, an expression or another column.
 *
 * @internal
 */
final readonly class Comparison implements Condition
{
    /** @var list<string> */
    private const array OPERATORS = ['=', '<>', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    public string $operator;

    public function __construct(
        private string|Sql $column,
        string $operator,
        private mixed $value,
        private bool $columnValue = false,
    ) {
        $normalised = strtoupper(trim($operator));
        if (!in_array($normalised, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported operator "%s"; use one of %s, or Sql::raw().', $operator, implode(', ', self::OPERATORS)));
        }
        if ($value === null && !$columnValue) {
            throw new InvalidArgumentException('Comparing with null is never true in SQL; use whereNull() or whereNotNull().');
        }
        $this->operator = $normalised === '!=' ? '<>' : $normalised;
    }

    public function compile(Output $out): string
    {
        $left = $this->column instanceof Sql ? $out->raw($this->column) : $out->identifier($this->column);
        $right = $this->columnValue && is_string($this->value) ? $out->identifier($this->value) : $out->value($this->value);

        return sprintf('%s %s %s', $left, $this->operator, $right);
    }
}
