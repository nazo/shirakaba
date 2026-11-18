<?php

declare(strict_types=1);

namespace Shirakaba\Query\Internal;

use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;

/**
 * @internal
 */
final readonly class InList implements Condition
{
    /**
     * @param list<mixed>|Select $values
     */
    public function __construct(
        private string|Sql $column,
        private array|Select $values,
        private bool $not,
    ) {}

    public function compile(Output $out): string
    {
        // "IN ()" is a syntax error; an empty list matches nothing (or everything for NOT IN).
        if ($this->values === []) {
            return $this->not ? '1 = 1' : '1 = 0';
        }
        $column = $this->column instanceof Sql ? $out->raw($this->column) : $out->identifier($this->column);
        $list = $this->values instanceof Select
            ? $this->values->compileInto($out)
            : implode(', ', array_map($out->value(...), $this->values));

        return sprintf('%s %sIN (%s)', $column, $this->not ? 'NOT ' : '', $list);
    }
}
