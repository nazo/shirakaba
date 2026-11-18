<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * A dotted property path such as user.name.
 *
 * @internal
 */
final readonly class Path
{
    /**
     * @param non-empty-list<string> $segments
     */
    public function __construct(
        public array $segments,
        public Position $at,
    ) {}

    public function toString(): string
    {
        return implode('.', $this->segments);
    }
}
