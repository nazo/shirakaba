<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class UserView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}
}
