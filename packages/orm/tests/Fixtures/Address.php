<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class Address
{
    public function __construct(
        public string $city,
        public string $zip,
    ) {}
}
