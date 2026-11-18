<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use Attribute;

/**
 * A positional argument: `#[Argument] string $file`. Arguments are read in declaration
 * order; one with a default is optional, a variadic one takes the rest.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Argument
{
    public function __construct(
        public string $description = '',
    ) {}
}
