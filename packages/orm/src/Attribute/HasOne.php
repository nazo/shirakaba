<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * One $entity references this entity through $foreignKey (a property of $entity).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class HasOne
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
