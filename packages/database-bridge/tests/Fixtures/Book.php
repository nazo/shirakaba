<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests\Fixtures;

use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('bridge_books')]
#[BelongsTo('shelf', Shelf::class, 'shelfId')]
final readonly class Book
{
    public function __construct(
        public int $shelfId,
        public string $title,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
