<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\JsonDto;

#[JsonDto(name: 'Note')]
final readonly class Draft
{
    public function __construct(
        public string $text,
        public bool $pinned = false,
    ) {}
}
