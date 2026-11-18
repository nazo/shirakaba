<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Entities::delete() sets $property (a nullable DateTimeImmutable) instead of deleting the
 * row, and queries skip such rows through the NotDeleted scope. Entities::restore() and
 * Entities::forceDelete() undo or complete the deletion.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SoftDelete
{
    public function __construct(
        public string $property = 'deletedAt',
    ) {}
}
