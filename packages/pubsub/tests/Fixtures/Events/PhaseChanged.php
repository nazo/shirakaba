<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests\Fixtures\Events;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class PhaseChanged
{
    public function __construct(
        public int $seq,
        public string $phase,
    ) {}
}
