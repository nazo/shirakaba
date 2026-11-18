<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('orm_comments')]
#[BelongsTo('author', Author::class, 'authorId')]
#[BelongsTo('post', Post::class, 'postId')]
final readonly class Comment
{
    public function __construct(
        public int $postId,
        public AuthorId $authorId,
        public string $body,
        public bool $approved = false,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
