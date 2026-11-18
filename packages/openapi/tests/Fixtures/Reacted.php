<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests\Fixtures;

use Shirakaba\Json\JsonDto;
use Shirakaba\Validation\Length;

#[JsonDto]
final readonly class Reacted implements Activity
{
    public function __construct(
        public string $user,
        #[Length(max: 8)] public string $reaction = '+1',
    ) {}
}
