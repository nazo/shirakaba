<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;

/**
 * Requires a string to match a regular expression somewhere, like JSON Schema's
 * "pattern"; anchor it with ^ and $ to match the whole string.
 *
 * The pattern is given without delimiters and is evaluated by PCRE in Unicode mode.
 * Keep to the syntax PCRE and ECMA-262 share (classes, quantifiers, groups, anchors) so
 * that clients reading the published schema validate the same way.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Pattern implements Constraint
{
    private string $regex;

    /**
     * @throws InvalidArgumentException when the pattern does not compile
     */
    public function __construct(
        public string $pattern,
        /** Shown to clients instead of the pattern, such as "must be a slug". */
        public string $message = 'has an invalid format',
    ) {
        $this->regex = '/' . str_replace('/', '\/', $pattern) . '/u';
        if (@preg_match($this->regex, '') === false) {
            throw new InvalidArgumentException(sprintf('Invalid pattern "%s".', $pattern));
        }
    }

    public function violation(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return preg_match($this->regex, $value) === 1 ? null : $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return ['pattern' => $this->pattern];
    }
}
