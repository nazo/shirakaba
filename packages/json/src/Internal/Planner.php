<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use BackedEnum;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use Shirakaba\Core\Description\DocComment;
use Shirakaba\Core\Extraction\Constraint;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Json\JsonName;
use Shirakaba\Json\JsonUnion;
use Shirakaba\Json\ListOf;
use Shirakaba\Json\MapOf;
use Shirakaba\Json\Optional;
use Shirakaba\Json\OptionalOf;

/**
 * Builds the mapping plan for a DTO class from reflection, once, at build time (design/06-json-and-openapi.md).
 *
 * @internal
 */
final readonly class Planner
{
    public function __construct(
        private bool $allowUnknownFields,
        /** Prefix for error messages, such as 'Route "POST /users", parameter $input'. */
        private string $context,
    ) {}

    /**
     * The plan for a body, response or column type: a #[JsonUnion] or a DTO class.
     *
     * @throws InvalidApplication when the type cannot be mapped
     */
    public function root(string $class): ObjectNode|UnionNode
    {
        return self::union($class) !== null ? $this->unionNode($class, false, []) : $this->object($class, false);
    }

    /**
     * @return JsonUnion|null the union declared on $class
     */
    private static function union(string $class): ?JsonUnion
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return null;
        }

        return (new ReflectionClass($class)->getAttributes(JsonUnion::class)[0] ?? null)?->newInstance();
    }

    /**
     * @param list<class-string> $stack
     * @throws InvalidApplication when the union or one of its members cannot be mapped
     */
    private function unionNode(string $class, bool $nullable, array $stack): UnionNode
    {
        $union = self::union($class);
        if ($union === null || !class_exists($class) && !interface_exists($class)) {
            throw new InvalidApplication(sprintf('%s: %s is not a #[JsonUnion].', $this->context, $class));
        }
        if (in_array($class, $stack, true)) {
            throw new InvalidApplication(sprintf('%s: %s refers to itself; recursive DTOs are not supported.', $this->context, $class));
        }
        if ($union->discriminator === '' || $union->members === []) {
            throw new InvalidApplication(sprintf('%s: #[JsonUnion] on %s needs a discriminator name and at least one member.', $this->context, $class));
        }
        $stack[] = $class;
        $members = [];
        foreach ($union->members as $tag => $member) {
            $where = sprintf('#[JsonUnion] on %s, member "%s"', $class, $tag);
            if ($tag === '' || !class_exists($member) || !is_a($member, $class, true)) {
                throw new InvalidApplication(sprintf(
                    '%s: %s must be a non-empty value naming a class that extends or implements %s.',
                    $this->context,
                    $where,
                    $class,
                ));
            }
            if (new ReflectionClass($member)->getAttributes(\Shirakaba\Json\JsonDto::class) === []) {
                throw new InvalidApplication(sprintf('%s: %s is %s, which must be a #[JsonDto] so Json can write it.', $this->context, $where, $member));
            }
            $members[(string) $tag] = $this->object($member, false, $stack);
        }

        return new UnionNode($class, $union->discriminator, $members, $nullable, $union->name);
    }

    /**
     * @param list<class-string> $stack classes being planned, to detect recursion
     * @throws InvalidApplication when the class cannot be mapped
     */
    public function object(string $class, bool $nullable, array $stack = []): ObjectNode
    {
        if (in_array($class, $stack, true)) {
            throw new InvalidApplication(sprintf('%s: %s refers to itself; recursive DTOs are not supported.', $this->context, $class));
        }
        if (!class_exists($class)) {
            throw new InvalidApplication(sprintf('%s: %s is not a class.', $this->context, $class));
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new InvalidApplication(sprintf('%s: %s cannot be instantiated.', $this->context, $class));
        }

        $stack[] = $class;
        $fields = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                throw new InvalidApplication(sprintf('%s: %s::__construct() has a variadic parameter.', $this->context, $class));
            }
            $hasDefault = $parameter->isDefaultValueAvailable();
            $optional = $this->optional($parameter, $class, $stack);
            $jsonName = ($parameter->getAttributes(JsonName::class)[0] ?? null)?->newInstance()->name;
            $fields[] = new Field(
                $jsonName ?? $parameter->getName(),
                $optional ?? $this->node($parameter, $class, $stack),
                $hasDefault,
                $hasDefault && $optional === null ? $parameter->getDefaultValue() : null,
                array_map(
                    static fn (ReflectionAttribute $attribute): Constraint => $attribute->newInstance(),
                    $parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF),
                ),
                $optional !== null,
                $jsonName === null ? null : $parameter->getName(),
                DocComment::ofParameter($parameter),
            );
        }

        $dto = ($reflection->getAttributes(\Shirakaba\Json\JsonDto::class)[0] ?? null)?->newInstance();
        try {
            $tag = JsonUnion::tagOf($class);
        } catch (\LogicException $e) {
            throw new InvalidApplication(sprintf('%s: %s', $this->context, $e->getMessage()), 0, $e);
        }
        if ($tag !== null && in_array($tag[0], array_map(static fn (Field $field): string => $field->name, $fields), true)) {
            throw new InvalidApplication(sprintf(
                '%s: %s is a #[JsonUnion] member, so it must not declare the discriminator field "%s"; Json writes it from the union.',
                $this->context,
                $class,
                $tag[0],
            ));
        }

        return new ObjectNode($class, $fields, $this->allowUnknownFields, $nullable, $dto?->name, $tag[0] ?? null, $tag[1] ?? '');
    }

    /**
     * The value node of an Optional field, or null for ordinary fields.
     *
     * @param list<class-string> $stack
     */
    private function optional(ReflectionParameter $parameter, string $class, array $stack): ?Node
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->getName() !== Optional::class) {
            return null;
        }
        $where = sprintf('%s::__construct() parameter $%s', $class, $parameter->getName());
        $optionalOf = $parameter->getAttributes(OptionalOf::class)[0] ?? null;
        if ($optionalOf === null || $type->allowsNull()) {
            throw new InvalidApplication(sprintf('%s: %s is an Optional; add #[OptionalOf(...)] and keep the type non-nullable.', $this->context, $where));
        }
        if (!$parameter->isDefaultValueAvailable()) {
            throw new InvalidApplication(sprintf('%s: %s is an Optional; give it the default "new Optional()".', $this->context, $where));
        }
        $declaration = $optionalOf->newInstance();

        return $this->typeNode($declaration->type, $declaration->nullable, $where, $stack);
    }

    /**
     * @param list<class-string> $stack
     */
    private function node(ReflectionParameter $parameter, string $class, array $stack): Node
    {
        $type = $parameter->getType();
        $where = sprintf('%s::__construct() parameter $%s', $class, $parameter->getName());
        if (!$type instanceof ReflectionNamedType) {
            throw new InvalidApplication(sprintf('%s: %s needs a single declared type, optionally nullable.', $this->context, $where));
        }

        if ($type->getName() === 'array') {
            $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;
            $mapOf = $parameter->getAttributes(MapOf::class)[0] ?? null;
            if ($listOf !== null && $mapOf !== null) {
                throw new InvalidApplication(sprintf('%s: %s has both #[ListOf] and #[MapOf]; it is either a list or a map.', $this->context, $where));
            }
            if ($mapOf !== null) {
                return new MapNode($this->typeNode($mapOf->newInstance()->type, false, $where, $stack), $type->allowsNull());
            }
            if ($listOf === null) {
                throw new InvalidApplication(sprintf(
                    '%s: %s is an array; add #[ListOf(...)] for a JSON array or #[MapOf(...)] for an object with arbitrary keys.',
                    $this->context,
                    $where,
                ));
            }

            return new ListNode($this->typeNode($listOf->newInstance()->type, false, $where, $stack), $type->allowsNull());
        }

        return $this->typeNode($type->getName(), $type->allowsNull(), $where, $stack);
    }

    /**
     * @param list<class-string> $stack
     */
    private function typeNode(string $name, bool $nullable, string $where, array $stack): Node
    {
        if (in_array($name, ['string', 'int', 'float', 'bool'], true)) {
            return new ScalarNode($name, $nullable);
        }
        if ($name === \DateTimeImmutable::class) {
            return new TimeNode('instant', $nullable);
        }
        if ($name === \Shirakaba\Time\LocalDate::class) {
            return new TimeNode('date', $nullable);
        }
        if (is_a($name, \DateTimeInterface::class, true)) {
            throw new InvalidApplication(
                sprintf('%s: %s is %s; use DateTimeImmutable for instants (design/07-date-and-time.md).', $this->context, $where, $name)
                    . \Shirakaba\Core\Messages::see('mutable-date-time'),
            );
        }
        if (enum_exists($name) && is_subclass_of($name, BackedEnum::class)) {
            return new EnumNode($name, (string) new ReflectionEnum($name)->getBackingType() === 'int', $nullable);
        }
        if (self::union($name) !== null) {
            return $this->unionNode($name, $nullable, $stack);
        }
        if (class_exists($name)) {
            return $this->object($name, $nullable, $stack);
        }

        throw new InvalidApplication(sprintf(
            '%s: %s has the unsupported type %s; use string, int, float, bool, a backed enum, DateTimeImmutable, LocalDate, a DTO class or #[ListOf] arrays.',
            $this->context,
            $where,
            $name,
        ));
    }
}
