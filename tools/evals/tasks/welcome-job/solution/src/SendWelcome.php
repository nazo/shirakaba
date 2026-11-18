<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class SendWelcome
{
    public function __construct(
        public int $userId,
    ) {}
}
