<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Json\JsonDto;

#[JsonDto]
final readonly class CompletedTasks
{
    public function __construct(
        public int $completed,
    ) {}
}
