<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\CreatedAt;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('notes')]
#[BelongsTo('author', User::class, 'authorId')]
final readonly class Note
{
    public function __construct(
        public int $authorId,
        public string $title,
        #[CreatedAt] public ?DateTimeImmutable $createdAt = null,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
