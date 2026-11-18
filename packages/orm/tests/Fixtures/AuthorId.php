<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

final readonly class AuthorId
{
    public function __construct(
        public int $value,
    ) {}
}
