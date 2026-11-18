<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use DateTimeImmutable;
use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\Column;
use Shirakaba\Orm\Attribute\CreatedAt;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Json;
use Shirakaba\Orm\Attribute\ManyToMany;
use Shirakaba\Orm\Attribute\Table;
use Shirakaba\Orm\Attribute\UpdatedAt;
use Shirakaba\Orm\Attribute\Version;
use Shirakaba\Time\LocalDate;

#[Table('orm_posts', label: 'post')]
#[BelongsTo('author', Author::class, 'authorId')]
#[HasMany('comments', Comment::class, 'postId', orderBy: 'id')]
#[ManyToMany('tags', Tag::class, through: 'orm_post_tags', localKey: 'post_id', foreignKey: 'tag_id', orderBy: 'position')]
final readonly class Post
{
    /**
     * @param array<array-key, mixed> $meta
     */
    public function __construct(
        #[Column('author_id')] public AuthorId $authorId,
        public string $title,
        public PostStatus $status = PostStatus::Draft,
        public ?LocalDate $publishedOn = null,
        #[Json] public array $meta = [],
        #[CreatedAt] public ?DateTimeImmutable $createdAt = null,
        #[UpdatedAt] public ?DateTimeImmutable $updatedAt = null,
        #[Version] public int $version = 0,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
