<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

/**
 * An attribute that restricts an already converted value (design/04-routing-and-handlers.md).
 *
 * Built-in path, query and header extractors apply the constraints on their parameter;
 * body extractors apply them to properties. Concrete constraints live in
 * shirakaba/validation; any package can add its own.
 */
interface Constraint
{
    /**
     * @return string|null null when the value is acceptable, otherwise a reason meant for
     *                     clients that does not repeat the value, such as "must be at least 1"
     */
    public function violation(mixed $value): ?string;

    /**
     * Describes the restriction with JSON Schema (2020-12) keywords, such as ["minimum" => 1].
     *
     * @return array<string, mixed>
     */
    public function keywords(): array;
}
