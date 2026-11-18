<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

/**
 * A position in a template, for errors.
 *
 * @internal
 */
final readonly class Position
{
    public function __construct(
        public int $line,
        public int $column,
    ) {}
}
