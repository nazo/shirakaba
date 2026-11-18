<?php

declare(strict_types=1);

namespace Shirakaba\Database;

use Shirakaba\Database\Observe\DatabaseEvent;

/**
 * Reported to observers for every statement a request runs. It carries the SQL
 * with its placeholders, never the parameter values.
 */
final readonly class StatementExecuted implements DatabaseEvent
{
    public function __construct(
        public string $sql,
        public float $seconds,
        /** The dialect name: "mysql", "pgsql" or "sqlite". */
        public string $system,
    ) {}

    public function name(): string
    {
        return 'db.statement';
    }

    public function duration(): float
    {
        return $this->seconds;
    }

    /**
     * Attribute names follow the OpenTelemetry database semantic conventions.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'db.system.name' => match ($this->system) {
                'pgsql' => 'postgresql',
                default => $this->system,
            },
            'db.query.text' => $this->sql,
        ];
    }
}
