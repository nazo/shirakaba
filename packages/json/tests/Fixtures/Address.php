<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Validation\Pattern;

#[JsonDto]
final readonly class Address
{
    public function __construct(
        public string $city,
        #[Pattern('^[0-9]{3}-[0-9]{4}$', 'must look like 123-4567')]
        public string $zip,
    ) {}
}
