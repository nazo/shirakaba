<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Fills a projection parameter from an entity property, relation or count of another name
 *: `#[From('notifier')] UserView $user` receives the relation requested with
 * with('notifier'), and `#[From('commentsCount')] int $comments` the withCount('comments')
 * count.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class From
{
    public function __construct(
        public string $name,
    ) {}
}
