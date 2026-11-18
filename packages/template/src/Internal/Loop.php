<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * The position in a for block: {{ loop.index }}, {% if loop.first %}, {% if loop.last %}.
 *
 * @internal
 */
final readonly class Loop
{
    public function __construct(
        /** 1 for the first item. */
        public int $index,
        public bool $first,
        public bool $last,
    ) {}
}
