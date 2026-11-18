<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Http\ProblemCode;

use Shirakaba\Core\Description\UnionType;
use stdClass;

/**
 * Maps a JSON object into the member of a #[JsonUnion] its discriminator names (design/06-json-and-openapi.md).
 *
 * @internal
 */
final readonly class UnionNode implements Node
{
    /**
     * @param class-string $class
     * @param non-empty-array<string, ObjectNode> $members
     */
    public function __construct(
        private string $class,
        private string $discriminator,
        private array $members,
        private bool $nullable,
        private ?string $schemaName = null,
    ) {}

    public function map(mixed $value, string $pointer, Problems $problems): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }
        if (!$value instanceof stdClass) {
            $problems->mapping($pointer, 'must be an object' . ($this->nullable ? ' or null' : ''));

            return null;
        }
        $tagPointer = Problems::pointer($pointer, $this->discriminator);
        if (!property_exists($value, $this->discriminator)) {
            $problems->mapping($tagPointer, 'is required', ProblemCode::REQUIRED);

            return null;
        }
        $tag = $value->{$this->discriminator};
        if (!is_string($tag) || !isset($this->members[$tag])) {
            $problems->mapping($tagPointer, 'must be one of: ' . implode(', ', array_map(
                static fn (int|string $tag): string => json_encode((string) $tag, JSON_THROW_ON_ERROR),
                array_keys($this->members),
            )), ProblemCode::INVALID_CHOICE);

            return null;
        }

        return $this->members[$tag]->map($value, $pointer, $problems);
    }

    public function type(): UnionType
    {
        $members = [];
        foreach ($this->members as $tag => $member) {
            $members[(string) $tag] = $member->type();
        }

        return new UnionType($this->class, $this->discriminator, $members, $this->nullable, $this->schemaName);
    }
}
