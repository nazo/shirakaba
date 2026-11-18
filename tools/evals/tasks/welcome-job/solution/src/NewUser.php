<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Validation\Email;
use Shirakaba\Validation\Length;

final readonly class NewUser
{
    public function __construct(
        #[Length(min: 1, max: 100)] public string $name,
        #[Email] public string $email,
    ) {}
}
