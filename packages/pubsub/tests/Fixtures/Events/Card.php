<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests\Fixtures\Events;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class Card
{
    public function __construct(
        public string $id,
        public string $body,
    ) {}
}
