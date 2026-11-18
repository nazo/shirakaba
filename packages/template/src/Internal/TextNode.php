<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * @internal
 */
final readonly class TextNode implements Node
{
    public function __construct(
        public string $text,
    ) {}
}
