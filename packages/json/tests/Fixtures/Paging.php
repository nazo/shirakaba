<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use Shirakaba\Json\JsonName;

final readonly class Paging
{
    public function __construct(
        #[JsonName('per_page')]
        public int $perPage,
    ) {}
}
