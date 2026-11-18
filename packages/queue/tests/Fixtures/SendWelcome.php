<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class SendWelcome
{
    public function __construct(
        public int $userId,
        public string $email,
    ) {}
}
