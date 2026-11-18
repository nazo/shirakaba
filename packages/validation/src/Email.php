<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Requires a syntactically valid e-mail address (PHP's FILTER_VALIDATE_EMAIL). It says
 * nothing about whether the address exists.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Email implements Constraint
{
    public function violation(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'must be an e-mail address' : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return ['format' => 'email'];
    }
}
