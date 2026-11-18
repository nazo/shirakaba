<?php

declare(strict_types=1);

namespace Shirakaba\Auth\Tests\Fixtures;

final readonly class User
{
    public function __construct(
        public string $id,
    ) {}
}
