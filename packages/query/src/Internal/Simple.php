<?php

declare(strict_types=1);

namespace Shirakaba\Query\Internal;

use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;

/**
 * IS NULL, BETWEEN, EXISTS and raw conditions.
 *
 * @internal
 */
final readonly class Simple implements Condition
{
    /**
     * @param 'null'|'between'|'exists'|'raw' $kind
     * @param array{mixed, mixed}|null $range
     */
    public function __construct(
        private string $kind,
        private string|Sql|Select $subject,
        private bool $not = false,
        private ?array $range = null,
    ) {}

    public function compile(Output $out): string
    {
        $subject = $this->subject;

        return match ($this->kind) {
            'raw' => $subject instanceof Sql ? '(' . $out->raw($subject) . ')' : '1 = 0',
            'exists' => ($this->not ? 'NOT ' : '') . 'EXISTS (' . ($subject instanceof Select ? $subject->compileInto($out) : '') . ')',
            'null' => sprintf('%s IS %sNULL', self::column($out, $subject), $this->not ? 'NOT ' : ''),
            'between' => sprintf(
                '%s %sBETWEEN %s AND %s',
                self::column($out, $subject),
                $this->not ? 'NOT ' : '',
                $out->value($this->range[0] ?? null),
                $out->value($this->range[1] ?? null),
            ),
        };
    }

    private static function column(Output $out, string|Sql|Select $subject): string
    {
        return match (true) {
            $subject instanceof Sql => $out->raw($subject),
            $subject instanceof Select => '(' . $subject->compileInto($out) . ')',
            default => $out->identifier($subject),
        };
    }
}
