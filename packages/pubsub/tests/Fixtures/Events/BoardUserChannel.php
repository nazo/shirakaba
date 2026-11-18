<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests\Fixtures\Events;

use Shirakaba\PubSub\ChannelEvent;
use Shirakaba\PubSub\EventChannel;

#[EventChannel('board/{boardId}/user/{userId}', private: true)]
#[ChannelEvent('card.added', CardAdded::class)]
final readonly class BoardUserChannel
{
    public function __construct(
        public string $boardId,
        public int $userId,
    ) {}
}
