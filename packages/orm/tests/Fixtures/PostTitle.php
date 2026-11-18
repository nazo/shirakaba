<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use DateTimeInterface;

/**
 * A response DTO filled by property name, with non-nullable generated fields and a wider
 * type for createdAt.
 */
final readonly class PostTitle
{
    public function __construct(
        public int $id,
        public string $title,
        public PostStatus $status,
        public DateTimeInterface $createdAt,
        public int $version,
    ) {}
}
