<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;
use LogicException;
use ReflectionClass;
use Shirakaba\Json\Internal\EncodedMap;

/**
 * Lets Json encode a class from its constructor fields, the same fields #[JsonResponse]
 * and #[JsonBody] describe, so the declared type and the body cannot drift (design/06-json-and-openapi.md):
 *
 *     #[JsonDto]
 *     final readonly class Task { public function __construct(public int $id, ...) {} }
 *
 * Every constructor parameter must be a promoted public property. #[JsonName] applies.
 * A member of a #[JsonUnion] is written with its discriminator first (design/06-json-and-openapi.md).
 * The attribute is an explicit opt-in: other objects are still refused (design/06-json-and-openapi.md).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class JsonDto
{
    /**
     * @param string|null $name the public name in API descriptions (OpenAPI schema names);
     *                          the short class name when null, so renaming the class renames
     *                          the API type unless a name is given
     */
    public function __construct(
        public ?string $name = null,
    ) {}

    /**
     * @return array<string, mixed>
     * @throws LogicException when a constructor parameter is not a promoted public property
     * @internal used by Json
     */
    public static function fields(object $dto): array
    {
        $tag = JsonUnion::tagOf($dto::class);
        $fields = $tag === null ? [] : [$tag[0] => $tag[1]];
        foreach (new ReflectionClass($dto)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $property = new \ReflectionProperty($dto, $name);
            if (!$parameter->isPromoted() || !$property->isPublic()) {
                throw new LogicException(sprintf('%s is a #[JsonDto], so constructor parameter $%s must be a promoted public property.', $dto::class, $name));
            }
            $jsonName = ($parameter->getAttributes(JsonName::class)[0] ?? null)?->newInstance()->name ?? $name;
            $value = $property->getValue($dto);
            $fields[$jsonName] = is_array($value) && $parameter->getAttributes(MapOf::class) !== [] ? new EncodedMap($value) : $value;
        }

        return $fields;
    }
}
