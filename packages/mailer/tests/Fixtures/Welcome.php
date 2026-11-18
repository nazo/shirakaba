<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Tests\Fixtures;

final readonly class Welcome
{
    public function __construct(
        public string $name,
        public string $link,
    ) {}
}
