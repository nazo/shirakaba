<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use Shirakaba\Core\Observe\Event;
use Shirakaba\Database\Observe\DatabaseEvent;

/**
 * An event of the database packages as request observers receive it (design/08-database.md). Observers
 * that only use name, duration and attributes need nothing else; those that handle a known
 * event read it from $event:
 *
 *     if ($event instanceof RecordedDatabaseEvent && $event->event instanceof StatementExecuted) { ... }
 */
final readonly class RecordedDatabaseEvent implements Event
{
    public function __construct(
        public DatabaseEvent $event,
    ) {}

    public function name(): string
    {
        return $this->event->name();
    }

    public function duration(): ?float
    {
        return $this->event->duration();
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function attributes(): array
    {
        return $this->event->attributes();
    }
}
