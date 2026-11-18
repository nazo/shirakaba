<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

final readonly class Recursive
{
    public function __construct(
        public ?Recursive $child = null,
    ) {}
}
