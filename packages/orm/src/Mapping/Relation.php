<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Mapping;

/**
 * A relation declared on an entity class.
 *
 * @internal
 */
final readonly class Relation
{
    /**
     * @param 'belongsTo'|'hasOne'|'hasMany'|'manyToMany' $kind
     * @param class-string $entity the related entity
     * @param string $foreignKey belongsTo: a property of the owner; hasOne/hasMany: a property
     *                           of the related entity; manyToMany: the join table's column
     *                           referencing the related entity
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $entity,
        public string $foreignKey,
        public ?string $through = null,
        public ?string $localKey = null,
        public ?string $orderBy = null,
    ) {}

    public function isList(): bool
    {
        return $this->kind === 'hasMany' || $this->kind === 'manyToMany';
    }
}
