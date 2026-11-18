<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Declares the item class of a list property in a projection, when the items are
 * projections themselves: `#[Items(CommentView::class)] public array $comments`. Without
 * it, the items are the related entities.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Items
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
    ) {}
}
