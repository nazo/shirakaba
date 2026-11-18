<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * A parsed template file.
 *
 * @internal
 */
final readonly class Parsed
{
    /**
     * @param list<Node> $nodes
     * @param string|null $layout the layout template's name, without extension
     */
    public function __construct(
        public string $name,
        public bool $html,
        public array $nodes,
        public ?string $layout,
    ) {}
}
