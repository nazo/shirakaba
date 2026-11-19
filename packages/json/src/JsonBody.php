<?php

declare(strict_types=1);

namespace Shirakaba\Json;

use Attribute;
use InvalidArgumentException;
use ReflectionNamedType;
use Shirakaba\Core\Description\DescribesParameter;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\BodyExtractor;
use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Json\Internal\JsonBodyResolver;
use Shirakaba\Json\Internal\ObjectNode;
use Shirakaba\Json\Internal\Planner;
use Shirakaba\Json\Internal\UnionNode;

/**
 * Maps a JSON request body into a DTO: `#[JsonBody] CreateUser $input` (design/06-json-and-openapi.md).
 *
 * Checks, in order: media type (415), size and member count (413), syntax (400),
 * structure (400 with field errors), constraints (422 with field errors).
 *
 * The member count bounds the work json_decode() does: PHP's hash tables are not
 * randomised, so an object whose keys collide costs quadratic time. A 1 MiB body of
 * colliding keys takes about a second to decode; 10,000 members take about 0.1 s.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class JsonBody implements BodyExtractor, DescribesParameter
{
    /**
     * @param positive-int $maxBytes
     * @param positive-int $maxDepth
     * @param positive-int $maxMembers object members in the whole document
     * @throws InvalidArgumentException when a limit is not positive
     */
    public function __construct(
        public int $maxBytes = 1_048_576,
        public int $maxDepth = 64,
        public bool $allowUnknownFields = false,
        public int $maxMembers = 10_000,
    ) {
        if ($maxBytes < 1 || $maxDepth < 1 || $maxMembers < 1) {
            throw new InvalidArgumentException('JsonBody limits must be positive.');
        }
    }

    public function plan(HandlerParameter $parameter): ArgumentResolver
    {
        $node = $this->mapping($parameter);
        $maxBytes = $this->maxBytes;
        $maxDepth = $this->maxDepth;
        $maxMembers = $this->maxMembers;

        return new JsonBodyResolver($node, $maxBytes, $maxDepth, $maxMembers);
    }

    public function describe(HandlerParameter $parameter): ParameterDescription
    {
        return new ParameterDescription(
            ParameterDescription::BODY,
            '',
            $parameter->name(),
            $this->mapping($parameter)->type(),
            required: true,
            mediaTypes: ['application/json'],
        );
    }

    /**
     * Whether a Content-Type header names JSON: application/json or any "+json" type.
     */
    public static function isJsonMediaType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return (
            $mediaType === 'application/json'
                || preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+\+json$#D', $mediaType) === 1
        );
    }

    private function mapping(HandlerParameter $parameter): ObjectNode|UnionNode
    {
        $type = $parameter->reflection->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
        if ($class === null || $type === null || $type->allowsNull()) {
            throw new InvalidApplication(sprintf('%s: #[JsonBody] needs a non-nullable DTO class type.', $parameter->describe()));
        }

        return new Planner($this->allowUnknownFields, $parameter->describe())->root($class);
    }
}
