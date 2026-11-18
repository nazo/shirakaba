<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

final readonly class MutableEvent
{
    public function __construct(
        public \DateTime $at,
    ) {}
}
