<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * @internal
 */
final readonly class ForNode implements Node
{
    /**
     * @param list<Node> $body
     * @param list<Node> $else rendered when the list is empty
     */
    public function __construct(
        public string $variable,
        public Path $list,
        public array $body,
        public array $else,
    ) {}
}
