<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('people')]
final readonly class Person
{
    public function __construct(
        public string $name,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
