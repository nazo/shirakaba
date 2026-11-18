<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('orm_tags')]
final readonly class Tag
{
    public function __construct(
        public string $name,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
