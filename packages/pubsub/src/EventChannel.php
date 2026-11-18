<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use Attribute;

/**
 * Declares a class as a channel of typed hub events (design/11-real-time-events.md):
 *
 *     #[EventChannel('board/{boardId}', private: true, summary: 'Updates every participant of a board may see')]
 *     #[ChannelEvent('card.added', CardAdded::class)]
 *     #[ChannelEvent('phase.changed', PhaseChanged::class)]
 *     final readonly class BoardChannel
 *     {
 *         public function __construct(public string $boardId) {}
 *     }
 *
 * The constructor's promoted public parameters are the template's variables, exactly; they are
 * strings, integers or backed enums, percent-encoded into the topic.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EventChannel
{
    public function __construct(
        /** The topic template, appended to the publisher's topic prefix. */
        public string $channel,
        /** Whether updates are private, so subscribers need a token that names the topic. */
        public bool $private = false,
        public string $summary = '',
        /** The channel's key in documents; the class's short name without "Channel" when null. */
        public ?string $name = null,
    ) {}
}
