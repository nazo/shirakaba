<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests\Fixtures\Events;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class CardAdded
{
    public function __construct(
        public int $seq,
        public Card $card,
    ) {}
}
