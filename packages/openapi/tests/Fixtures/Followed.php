<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class Followed implements Activity
{
    public function __construct(
        public string $user,
    ) {}
}
