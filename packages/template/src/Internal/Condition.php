<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * @internal
 */
final readonly class Condition
{
    public function __construct(
        public Path $path,
        public bool $negated,
    ) {}
}
