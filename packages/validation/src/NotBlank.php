<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Requires a string with at least one non-whitespace character.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class NotBlank implements Constraint
{
    public function violation(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\S/u', $value) !== 1 ? 'must not be blank' : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return ['minLength' => 1, 'pattern' => '\S'];
    }
}
