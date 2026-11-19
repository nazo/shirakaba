<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * The position in a for block: {{ loop.index }}, {% if loop.first %}, {% if loop.last %}.
 *
 * Templates read its properties, which PHP code never does; `@api` tells the dead code
 * detector so (design/13-static-analysis-and-agents.md).
 *
 * @internal
 * @api
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
