<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Internal;

use BackedEnum;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Shirakaba\Json\JsonDto;
use Shirakaba\PubSub\ChannelEvent;
use Shirakaba\PubSub\EventChannel;

/**
 * A channel class read once: its template, parameters and events, checked (design/11-real-time-events.md).
 *
 * @internal
 */
final readonly class ChannelMap
{
    /**
     * @param class-string $class
     * @param array<string, 'string'|'int'|class-string<BackedEnum>> $parameters template variable => type
     * @param non-empty-array<string, class-string> $events type => payload class
     */
    private function __construct(
        public string $class,
        public string $name,
        public string $template,
        public array $parameters,
        public bool $private,
        public string $summary,
        public array $events,
    ) {}

    /**
     * @param class-string $class
     * @throws InvalidArgumentException when the class is not a valid channel
     */
    public static function of(string $class): self
    {
        $reflection = new ReflectionClass($class);
        $attribute = ($reflection->getAttributes(EventChannel::class)[0] ?? null)?->newInstance()
            ?? throw new InvalidArgumentException(sprintf('%s is not an #[EventChannel].', $class));
        $template = $attribute->channel;
        if (preg_match('#\A([A-Za-z0-9_.~-]+|\{[A-Za-z_][A-Za-z0-9_]*\})(/([A-Za-z0-9_.~-]+|\{[A-Za-z_][A-Za-z0-9_]*\}))*\z#', $template) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s: "%s" is not a channel template; use path segments of letters, digits, "_", ".", "~", "-" and {variables}.',
                $class,
                $template,
            ));
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $matches);
        $variables = $matches[1];
        if (count($variables) !== count(array_unique($variables))) {
            throw new InvalidArgumentException(sprintf('%s: the template "%s" names a variable twice.', $class, $template));
        }

        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType && !$type->allowsNull() ? $type->getName() : null;
            if (!$parameter->isPromoted() || $name === null || !(in_array($name, ['string', 'int'], true) || is_subclass_of($name, BackedEnum::class))) {
                throw new InvalidArgumentException(sprintf(
                    '%s: parameter $%s must be a promoted string, int or backed enum, as channel parameters are.',
                    $class,
                    $parameter->getName(),
                ));
            }
            $parameters[$parameter->getName()] = $name === 'string' || $name === 'int' ? $name : self::enum($name);
        }
        $missing = array_diff($variables, array_keys($parameters));
        $extra = array_diff(array_keys($parameters), $variables);
        if ($missing !== [] || $extra !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s: the template "%s" and the constructor must name the same parameters; %s.',
                $class,
                $template,
                implode('; ', [
                    ...($missing === [] ? [] : ['missing from the constructor: ' . implode(', ', $missing)]),
                    ...($extra === [] ? [] : ['missing from the template: ' . implode(', ', $extra)]),
                ]),
            ));
        }

        $events = [];
        foreach ($reflection->getAttributes(ChannelEvent::class) as $eventAttribute) {
            $event = $eventAttribute->newInstance();
            if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/', $event->type) !== 1) {
                throw new InvalidArgumentException(sprintf('%s: "%s" is not an event type; use lower-case words joined by "." or "_".', $class, $event->type));
            }
            if (isset($events[$event->type])) {
                throw new InvalidArgumentException(sprintf('%s declares the event "%s" twice.', $class, $event->type));
            }
            if (!class_exists($event->class) || new ReflectionClass($event->class)->getAttributes(JsonDto::class) === []) {
                throw new InvalidArgumentException(sprintf('%s: the payload of "%s" must be a #[JsonDto] class.', $class, $event->type));
            }
            foreach (new ReflectionClass($event->class)->getConstructor()?->getParameters() ?? [] as $field) {
                if ($field->getName() === 'type') {
                    throw new InvalidArgumentException(sprintf('%s: %s declares "type", which the channel writes itself.', $class, $event->class));
                }
            }
            $events[$event->type] = $event->class;
        }
        if ($events === []) {
            throw new InvalidArgumentException(sprintf('%s declares no #[ChannelEvent].', $class));
        }

        $short = $reflection->getShortName();
        $name = $attribute->name ?? lcfirst(str_ends_with($short, 'Channel') && $short !== 'Channel' ? substr($short, 0, -7) : $short);

        return new self($class, $name, $template, $parameters, $attribute->private, $attribute->summary, $events);
    }

    /**
     * @return class-string<BackedEnum>
     * @throws InvalidArgumentException
     */
    private static function enum(string $name): string
    {
        if (!is_subclass_of($name, BackedEnum::class)) {
            throw new InvalidArgumentException(sprintf('%s is not a backed enum.', $name));
        }

        return $name;
    }

    /**
     * The topic for an instance, with each parameter percent-encoded.
     */
    public function topic(object $channel): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $match) use ($channel): string {
                /** @var mixed $value */
                $value = new \ReflectionProperty($channel, $match[1])->getValue($channel);
                $value = $value instanceof BackedEnum ? $value->value : $value;

                return rawurlencode(is_int($value) || is_string($value) ? (string) $value : '');
            },
            $this->template,
        );
    }

    /**
     * The type under which $event is declared.
     *
     * @throws InvalidArgumentException when the channel does not declare its class
     */
    public function typeOf(object $event): string
    {
        $type = array_search($event::class, $this->events, true);
        if (!is_string($type)) {
            throw new InvalidArgumentException(sprintf(
                '%s does not declare an event of class %s; add #[ChannelEvent(..., %s::class)].',
                $this->class,
                $event::class,
                $event::class,
            ));
        }

        return $type;
    }
}
