<?php

declare(strict_types=1);

namespace Shirakaba\Database;

use Shirakaba\Database\Observe\DatabaseEvent;

/**
 * Reported when a request gets its database connection: a new one, with the time
 * connecting took, or one reused from an earlier request.
 */
final readonly class ConnectionLeased implements DatabaseEvent
{
    public function __construct(
        public bool $reused,
        /** Seconds spent connecting and configuring; 0 for a reused connection. */
        public float $seconds,
        /** The dialect name: "mysql", "pgsql" or "sqlite". */
        public string $system,
        /** Requests the connection served before this one. */
        public int $previousUses = 0,
    ) {}

    public function name(): string
    {
        return 'db.connection';
    }

    public function duration(): float
    {
        return $this->seconds;
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function attributes(): array
    {
        return [
            'db.system.name' => match ($this->system) {
                'pgsql' => 'postgresql',
                default => $this->system,
            },
            'db.connection.reused' => $this->reused,
            'db.connection.previous_uses' => $this->previousUses,
        ];
    }
}
