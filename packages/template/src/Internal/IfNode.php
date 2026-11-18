<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * if / elseif branches with an optional else.
 *
 * @internal
 */
final readonly class IfNode implements Node
{
    /**
     * @param non-empty-list<array{Condition, list<Node>}> $branches
     * @param list<Node> $else
     */
    public function __construct(
        public array $branches,
        public array $else,
    ) {}
}
