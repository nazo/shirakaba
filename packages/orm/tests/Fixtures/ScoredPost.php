<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Computed;
use Shirakaba\Orm\Attribute\Items;

final readonly class ScoredPost
{
    /**
     * @param list<ScoredComment> $comments
     */
    public function __construct(
        public Post $post,
        #[Items(ScoredComment::class)] public array $comments,
        #[Computed] public int $score = 0,
    ) {}
}
