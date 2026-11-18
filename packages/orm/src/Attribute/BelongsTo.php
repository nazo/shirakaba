<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * This entity references one $entity through $foreignKey (a property of this entity).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class BelongsTo
{
    /**
     * @param class-string $entity
     */
    public function __construct(
        public string $name,
        public string $entity,
        public string $foreignKey,
    ) {}
}
