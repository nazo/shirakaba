<?php

declare(strict_types=1);

namespace Shirakaba\Template\Tests\Fixtures;

final readonly class Item
{
    public function __construct(
        public string $title,
    ) {}
}
