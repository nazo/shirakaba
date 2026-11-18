<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Items;

final readonly class TaggedPost
{
    /**
     * @param list<Tag> $tags
     * @param list<TaggedComment> $comments
     */
    public function __construct(
        public Post $post,
        public array $tags,
        #[Items(TaggedComment::class)] public array $comments,
    ) {}
}
