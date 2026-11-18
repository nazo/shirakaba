<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;
use LogicException;
use ReflectionClass;

/**
 * Declares a tagged union of #[JsonDto] classes on the type they share, usually an interface
 * (design/06-json-and-openapi.md):
 *
 *     #[JsonUnion('type', [
 *         'follow' => FollowNotification::class,
 *         'reaction' => ReactionNotification::class,
 *     ])]
 *     interface Notification {}
 *
 * Json writes the discriminator into each member's object ("type": "follow"), #[JsonBody]
 * and JsonMapper pick the member class by it, and #[JsonResponse(200, Notification::class)]
 * is described as oneOf with a discriminator. Members declare no field of that name; the
 * value comes from this mapping, so it cannot disagree with the class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class JsonUnion
{
    /**
     * @param non-empty-array<string, class-string> $members discriminator value => member class
     * @param string|null $name the public name in API descriptions; the short class name when null
     */
    public function __construct(
        public string $discriminator,
        public array $members,
        public ?string $name = null,
    ) {}

    /**
     * The discriminator name and value Json writes for an object of $class, or null when the
     * class is no union's member.
     *
     * @param class-string $class
     * @return array{string, string}|null
     * @throws LogicException when the class is a member of more than one union
     * @internal used by Json and the mapping planner
     */
    public static function tagOf(string $class): ?array
    {
        $found = null;
        $reflection = new ReflectionClass($class);
        $types = $reflection->getInterfaces();
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $types[] = $parent;
        }
        foreach ($types as $type) {
            foreach ($type->getAttributes(self::class) as $attribute) {
                $union = $attribute->newInstance();
                $value = array_search($class, $union->members, true);
                if ($value === false) {
                    continue;
                }
                if ($found !== null) {
                    throw new LogicException(sprintf('%s is a member of more than one #[JsonUnion]; a class can carry only one discriminator.', $class));
                }
                $found = [$union->discriminator, (string) $value];
            }
        }

        return $found;
    }
}
