<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class PostTags
{
    /**
     * @param list<Tag> $tags
     */
    public function __construct(
        public Post $post,
        public array $tags,
    ) {}
}
