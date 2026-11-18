<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Items;

final readonly class PostView
{
    /**
     * @param list<CommentView> $comments
     * @param list<Tag> $tags
     */
    public function __construct(
        public Post $post,
        public Author $author,
        #[Items(CommentView::class)] public array $comments,
        public array $tags,
    ) {}
}
