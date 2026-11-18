<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Embedded;
use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\HasMany;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Json;
use Shirakaba\Orm\Attribute\Table;

#[Table('orm_authors')]
#[HasMany('posts', Post::class, 'authorId')]
final readonly class Author
{
    public function __construct(
        public string $name,
        public string $email,
        #[Embedded] public ?Address $address = null,
        #[Json] public ?AuthorSettings $settings = null,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
