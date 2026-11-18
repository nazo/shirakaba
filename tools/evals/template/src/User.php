<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Orm\Attribute\Generated;
use Shirakaba\Orm\Attribute\Id;
use Shirakaba\Orm\Attribute\Table;

#[Table('users')]
final readonly class User
{
    public function __construct(
        public string $name,
        public string $email,
        #[Id, Generated] public ?int $id = null,
    ) {}
}
