<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class PostSummary
{
    /**
     * @param list<Comment> $comments
     */
    public function __construct(
        public Post $post,
        public array $comments,
        public int $commentsCount,
        public int $tagsCount,
    ) {}
}
