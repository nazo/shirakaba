<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Orm\Attribute\BelongsTo;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('replies')]
#[BelongsTo('author', Person::class, 'authorId')]
final readonly class Reply
{
    public function __construct(
        public int $noteId,
        public int $authorId,
        public string $body,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
