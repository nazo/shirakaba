<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

final readonly class NoteCard
{
    public function __construct(
        public int $id,
        public string $body,
    ) {}
}
