<?php

declare(strict_types=1);

namespace Shirakaba\Template\Tests\Fixtures;

final readonly class User
{
    public function __construct(
        public string $name,
        public bool $admin = false,
        public bool $verified = true,
        public ?float $score = null,
    ) {}
}
