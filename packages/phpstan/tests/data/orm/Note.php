<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('notes')]
#[BelongsTo('author', Person::class, 'authorId')]
#[HasMany('replies', Reply::class, 'noteId')]
final readonly class Note
{
    public function __construct(
        public int $authorId,
        public string $body,
        public bool $pinned = false,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
