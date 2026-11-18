<?php

declare(strict_types=1);

namespace Shirakaba\QueueRedis\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class Ping
{
    public function __construct(
        public int $n,
    ) {}
}
