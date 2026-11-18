<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * A Server-Sent Events stream: the events it may send, by event name, with the type of each
 * event's data (design/11-real-time-events.md). Data described as an object or union is JSON text.
 */
final readonly class EventStreamType implements Type
{
    /**
     * @param non-empty-array<string, Type> $events event name => data type
     */
    public function __construct(
        public array $events,
    ) {}

    public function isNullable(): bool
    {
        return false;
    }
}
