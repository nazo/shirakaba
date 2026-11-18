<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use Attribute;

/**
 * One event of an EventChannel (design/11-real-time-events.md): its `type` in the payload and the #[JsonDto] class of
 * the rest of the payload, which does not declare `type` itself.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ChannelEvent
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $type,
        public string $class,
    ) {}
}
