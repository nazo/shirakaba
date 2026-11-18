<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use InvalidArgumentException;
use Shirakaba\Database\Writer;
use Shirakaba\PubSub\Internal\ChannelMap;

/**
 * Publishes typed events on #[EventChannel]s through a Mercure hub (design/11-real-time-events.md):
 *
 *     $events = new MercureEvents($publisher);                         // application-scoped
 *     $events->publish($db, new BoardChannel($board->id), new CardAdded($board->seq, $card));
 *
 * The update carries `{"type": "card.added", ...}` without an SSE event name, so every update
 * reaches EventSource.onmessage; it is sent after the writer's transaction commits, privately
 * when the channel is private. Several channels of one class get one update with several topics.
 */
final readonly class MercureEvents
{
    public function __construct(
        private MercurePublisher $publisher,
    ) {}

    /**
     * @param object|non-empty-list<object> $channels instances of one #[EventChannel] class
     * @throws InvalidArgumentException for an event the channel does not declare, or channels of different classes
     * @throws \UnexpectedValueException when the event holds an object Json cannot encode
     */
    public function publish(Writer $db, object|array $channels, object $event): void
    {
        $channels = is_array($channels) ? $channels : [$channels];
        $first = $channels[0] ?? throw new InvalidArgumentException('Publish to at least one channel.');
        $map = ChannelMap::of($first::class);
        $map->typeOf($event);
        $topics = [];
        foreach ($channels as $channel) {
            if ($channel::class !== $map->class) {
                throw new InvalidArgumentException(sprintf('One update goes to channels of one class; got %s and %s.', $map->class, $channel::class));
            }
            $topics[] = $map->topic($channel);
        }
        $this->publisher->publishTo($db, $topics, EventChannels::payload($first, $event), $map->private);
    }
}
