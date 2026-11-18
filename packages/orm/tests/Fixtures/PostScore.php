<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Computed;
use Shirakaba\Orm\Attribute\From;

final readonly class PostScore
{
    public function __construct(
        #[From('title')] public string $name,
        #[Computed] public int $score,
    ) {}
}
