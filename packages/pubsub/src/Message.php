<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

/**
 * One published message as a subscriber receives it.
 */
final readonly class Message
{
    public function __construct(
        public string $channel,
        public string $payload,
    ) {}
}
