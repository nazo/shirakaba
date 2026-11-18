<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * Links this entity to many $entity through a join table with two key columns. $orderBy names
 * a column of the join table that orders the related entities, such as the position of an
 * attachment.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ManyToMany
{
    /**
     * @param class-string $entity
     */
    public function __construct(
        public string $name,
        public string $entity,
        public string $through,
        public string $localKey,
        public string $foreignKey,
        public ?string $orderBy = null,
    ) {}
}
