<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * A channel of events served by a hub rather than by a route (design/11-real-time-events.md), for tooling such as the
 * OpenAPI generator: its topic template, the template's parameters, whether subscribers need a
 * token, and the union of its event payloads.
 */
final readonly class EventChannelDescription
{
    /**
     * @param string $name the channel's key in documents, such as "board"
     * @param string $channel the topic template, such as "board/{boardId}"
     * @param array<string, ScalarType|EnumType> $parameters template variable => type
     */
    public function __construct(
        public string $name,
        public string $channel,
        public array $parameters,
        public bool $private,
        public string $summary,
        public UnionType $payload,
    ) {}
}
