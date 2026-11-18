<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use InvalidArgumentException;
use Shirakaba\Core\Description\ConstType;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\EventChannelDescription;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\Property;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\UnionType;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonResponse;
use Shirakaba\PubSub\Internal\ChannelMap;

/**
 * Reads #[EventChannel] classes (design/11-real-time-events.md): their topics, their payloads and their description
 * for the OpenAPI generator:
 *
 *     new Generator($info, eventChannels: EventChannels::describe(BoardChannel::class, BoardUserChannel::class));
 */
final readonly class EventChannels
{
    /**
     * @param class-string ...$classes
     * @return list<EventChannelDescription>
     * @throws InvalidArgumentException for a class that is not a valid channel
     */
    public static function describe(string ...$classes): array
    {
        $descriptions = [];
        foreach (array_values($classes) as $class) {
            $map = ChannelMap::of($class);
            $members = [];
            foreach ($map->events as $type => $payload) {
                $body = new JsonResponse(200, $payload)->responseDescription()->body;
                if (!$body instanceof ObjectType) {
                    throw new InvalidArgumentException(sprintf('%s: the payload of "%s" must be an object.', $class, $type));
                }
                $members[$type] = new ObjectType(
                    $body->class,
                    [new Property('type', new ConstType($type), true), ...$body->properties],
                    $body->additionalProperties,
                    false,
                    $body->name,
                    true,
                );
            }
            $parameters = array_map(
                static fn (string $type): ScalarType|EnumType => $type === 'string' || $type === 'int' ? new ScalarType($type) : EnumType::of($type),
                $map->parameters,
            );
            $descriptions[] = new EventChannelDescription(
                $map->name,
                $map->template,
                $parameters,
                $map->private,
                $map->summary,
                new UnionType($map->class, 'type', $members, name: ucfirst($map->name) . 'Event', output: true),
            );
        }

        return $descriptions;
    }

    /**
     * The topic of a channel instance, without the publisher's prefix: the template with each
     * parameter percent-encoded, such as "board/a%20b".
     *
     * @throws InvalidArgumentException when the object is not a valid channel
     */
    public static function topic(object $channel): string
    {
        return ChannelMap::of($channel::class)->topic($channel);
    }

    /**
     * The JSON payload of $event on $channel: `{"type": ..., ...the event's fields}`.
     *
     * @throws InvalidArgumentException when the channel does not declare the event
     * @throws \UnexpectedValueException when the event holds an object Json cannot encode
     */
    public static function payload(object $channel, object $event): string
    {
        $type = ChannelMap::of($channel::class)->typeOf($event);
        $fields = Json::encode($event);

        return '{"type":' . json_encode($type, JSON_THROW_ON_ERROR) . ($fields === '{}' ? '}' : ',' . substr($fields, 1));
    }
}
