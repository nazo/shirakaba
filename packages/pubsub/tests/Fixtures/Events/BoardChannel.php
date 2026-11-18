<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests\Fixtures\Events;

use Shirakaba\PubSub\ChannelEvent;
use Shirakaba\PubSub\EventChannel;

#[EventChannel('board/{boardId}', private: true, summary: 'Updates every participant of a board may see')]
#[ChannelEvent('card.added', CardAdded::class)]
#[ChannelEvent('phase.changed', PhaseChanged::class)]
final readonly class BoardChannel
{
    public function __construct(
        public string $boardId,
    ) {}
}
