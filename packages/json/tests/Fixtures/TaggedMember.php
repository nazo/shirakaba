<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class TaggedMember implements BadUnion
{
    public function __construct(
        public string $type,
    ) {}
}
