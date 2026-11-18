<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Http\ProblemCode;

use BackedEnum;
use InvalidArgumentException;
use Shirakaba\Core\Description\ConstType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\Property;
use Shirakaba\Json\Optional;
use stdClass;

/**
 * Maps a JSON object into a DTO through its constructor (design/06-json-and-openapi.md).
 *
 * @internal
 */
final readonly class ObjectNode implements Node
{
    /**
     * @param class-string $class
     * @param list<Field> $fields
     */
    public function __construct(
        private string $class,
        private array $fields,
        private bool $allowUnknownFields,
        private bool $nullable,
        private ?string $schemaName = null,
        /** The discriminator name and value of a #[JsonUnion] member (design/06-json-and-openapi.md). */
        private ?string $tagName = null,
        private string $tagValue = '',
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

        $before = $problems->count();
        $properties = get_object_vars($value);
        if ($this->tagName !== null) {
            if (!array_key_exists($this->tagName, $properties)) {
                $problems->mapping(Problems::pointer($pointer, $this->tagName), 'is required', ProblemCode::REQUIRED);
            } elseif ($properties[$this->tagName] !== $this->tagValue) {
                $problems->mapping(Problems::pointer($pointer, $this->tagName), 'must be ' . json_encode($this->tagValue, JSON_THROW_ON_ERROR), ProblemCode::INVALID_CHOICE);
            }
            unset($properties[$this->tagName]);
        }
        $arguments = [];
        foreach ($this->fields as $field) {
            $fieldPointer = Problems::pointer($pointer, $field->name);
            if (!array_key_exists($field->name, $properties)) {
                if (!$field->hasDefault) {
                    $problems->mapping($fieldPointer, 'is required', ProblemCode::REQUIRED);
                }
                continue;
            }

            $fieldBefore = $problems->count();
            $mapped = $field->node->map($properties[$field->name], $fieldPointer, $problems);
            unset($properties[$field->name]);
            if ($problems->count() !== $fieldBefore) {
                continue;
            }
            foreach ($field->constraints as $constraint) {
                $violation = $mapped === null ? null : $constraint->violation($mapped instanceof BackedEnum ? $mapped->value : $mapped);
                if ($violation !== null) {
                    $problems->constraint($fieldPointer, $violation);
                    break;
                }
            }
            $arguments[$field->parameter ?? $field->name] = $field->optional ? Optional::of($mapped) : $mapped;
        }

        if (!$this->allowUnknownFields) {
            foreach (array_keys($properties) as $unknown) {
                $problems->mapping(Problems::pointer($pointer, $unknown), 'is not an accepted field', ProblemCode::UNKNOWN_FIELD);
            }
        }

        if ($problems->count() !== $before) {
            return null;
        }

        try {
            return new ($this->class)(...$arguments);
        } catch (InvalidArgumentException) {
            // The DTO rejected the combination; its message is not meant for clients.
            $problems->constraint($pointer, 'is invalid');

            return null;
        }
    }

    public function type(): ObjectType
    {
        $tag = $this->tagName === null ? [] : [new Property($this->tagName, new ConstType($this->tagValue), required: true)];

        return new ObjectType(
            $this->class,
            [...$tag, ...array_map(
                static fn (Field $field): Property => new Property(
                    $field->name,
                    $field->node->type(),
                    required: !$field->hasDefault,
                    hasDefault: $field->hasDefault && !$field->optional,
                    default: $field->default instanceof BackedEnum ? $field->default->value : $field->default,
                    constraints: $field->constraints,
                    description: $field->description,
                ),
                $this->fields,
            )],
            $this->allowUnknownFields,
            $this->nullable,
            $this->schemaName,
        );
    }
}
