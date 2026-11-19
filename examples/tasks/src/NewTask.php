<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks;

use Shirakaba\Validation\Length;
use Shirakaba\Validation\NotBlank;

final readonly class NewTask
{
    public function __construct(
        #[NotBlank]
        #[Length(max: 200)] public string $title,
        #[Length(max: 2000)] public string $description = '',
    ) {}
}
