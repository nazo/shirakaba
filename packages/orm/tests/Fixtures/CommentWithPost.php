<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class CommentWithPost
{
    public function __construct(
        public Comment $comment,
        public ?Author $author,
        public Post $post,
    ) {}
}
