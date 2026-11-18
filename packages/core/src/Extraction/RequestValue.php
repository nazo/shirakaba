<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Shirakaba\Core\Description\Type;

/**
 * A domain type that path, query and header extractors can build from the request string,
 * so a rule is written once instead of on every parameter (design/04-routing-and-handlers.md):
 *
 *     final readonly class TaskId implements RequestValue { ... }
 *     public function show(#[Path] TaskId $id): Json
 */
interface RequestValue
{
    /**
     * @return static|null null when the value is invalid; the extractor answers 400
     */
    public static function fromRequestValue(string $value): ?static;

    /**
     * The raw value's type for descriptions (design/04-routing-and-handlers.md), such as `new ScalarType('int')`.
     */
    public static function requestValueType(): Type;

    /**
     * Constraints the raw value satisfies, for descriptions; they are not applied again.
     *
     * @return list<Constraint>
     */
    public static function requestValueConstraints(): array;
}
