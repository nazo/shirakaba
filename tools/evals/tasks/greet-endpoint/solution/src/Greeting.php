<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class Greeting
{
    public function __construct(
        public string $greeting,
    ) {}
}
