<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Computed;

final readonly class ScoredComment
{
    public function __construct(
        public Comment $comment,
        #[Computed] public int $score,
        #[Computed] public bool $mine = false,
    ) {}
}
