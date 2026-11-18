<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

/**
 * A Scope that can also tell whether a new entity belongs inside it, so that
 * Entities::insert() refuses an entity whose tenant was forged:
 *
 *     final readonly class InOrganization implements Scope, ScopeOnInsert
 *     {
 *         public function __construct(private string $organizationId) {}
 *         public function apply(EntityQuery $query): EntityQuery { return $query->where('organizationId', '=', $this->organizationId); }
 *         public function admits(object $entity): bool { return $entity->organizationId === $this->organizationId; }
 *     }
 */
interface ScopeOnInsert
{
    public function admits(object $entity): bool;
}
