<?php

declare(strict_types=1);

namespace Shirakaba\Database\Dialect;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Quoting
{
    /**
     * @throws InvalidArgumentException for an empty identifier or one containing NUL
     */
    public static function quote(string $identifier, string $quote): string
    {
        $parts = explode('.', $identifier);
        foreach ($parts as $part) {
            if ($part === '' || str_contains($part, "\0")) {
                throw new InvalidArgumentException(sprintf('Invalid identifier "%s".', $identifier));
            }
        }

        return implode('.', array_map(
            static fn (string $part): string => $part === '*' ? '*' : $quote . str_replace($quote, $quote . $quote, $part) . $quote,
            $parts,
        ));
    }

    public static function limit(?int $limit, ?int $offset, string $unlimited): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }
        if (($limit !== null && $limit < 0) || ($offset !== null && $offset < 0)) {
            throw new InvalidArgumentException('Limit and offset must not be negative.');
        }
        $clause = 'LIMIT ' . ($limit ?? $unlimited);

        return $offset === null || $offset === 0 ? $clause : $clause . ' OFFSET ' . $offset;
    }
}
