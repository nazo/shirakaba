<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

/**
 * The strict scalar parsing rules of the built-in extractors (design/04-routing-and-handlers.md), for reuse in
 * RequestValue implementations.
 */
final readonly class Scalar
{
    /**
     * Accepts only -?(0|[1-9][0-9]*) within the int range: no "+", whitespace, exponents
     * or leading zeros.
     */
    public static function int(string $value): ?int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $integer = (int) $value;

        // Out-of-range values saturate in a cast, so the round trip detects them (and "-0").
        return (string) $integer === $value ? $integer : null;
    }

    /**
     * Accepts true, false, 1 and 0.
     * @phpstan-pure
     */
    public static function bool(string $value): ?bool
    {
        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
