<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Many $entity reference this entity through $foreignKey (a property of $entity).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class HasMany
{
    /**
     * @param class-string $entity
     */
    public function __construct(
        public string $name,
        public string $entity,
        public string $foreignKey,
        public ?string $orderBy = null,
    ) {}
}
