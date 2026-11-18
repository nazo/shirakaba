<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * @internal
 */
final readonly class PrintNode implements Node
{
    public function __construct(
        public Path $path,
    ) {}
}
