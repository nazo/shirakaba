<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

final readonly class Owner
{
    public function __construct(
        public string $name,
    ) {}
}
