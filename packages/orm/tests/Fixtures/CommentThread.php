<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class CommentThread
{
    public function __construct(
        public Comment $comment,
        public Author $author,
        public PostCard $post,
    ) {}
}
