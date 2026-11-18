<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class TaggedComment
{
    public function __construct(
        public Comment $comment,
        public PostTags $post,
    ) {}
}
