<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\Attribute\Computed;
use Shirakaba\Orm\Attribute\From;

final readonly class PostDigest
{
    /**
     * @param list<string> $words
     */
    public function __construct(
        #[From('title')] public string $headline,
        #[From('author')] public Author $writer,
        #[From('commentsCount')] public int $comments,
        #[Computed] public array $words,
        #[Computed] public bool $starred = false,
    ) {}
}
