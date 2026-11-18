<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('bridge_shelves')]
#[HasMany('books', Book::class, 'shelfId', orderBy: 'id')]
final readonly class Shelf
{
    public function __construct(
        public string $name,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
