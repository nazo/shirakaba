<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Time\LocalDate;

/**
 * Entity properties by name and one relation, with no root entity parameter.
 */
final readonly class PostCard
{
    public function __construct(
        public int $id,
        public string $title,
        public PostStatus $status,
        public ?LocalDate $publishedOn,
        public Author $author,
    ) {}
}
