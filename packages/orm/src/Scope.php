<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

/**
 * A filter that applies to every query of an entity, such as "not deleted" or
 * "belongs to tenant 5". Declared on the entity with #[DefaultScope] (always applied; needs
 * a constructor without arguments) or #[RequiredScope] (the query must pass an instance,
 * because the value comes from the request):
 *
 *     final readonly class InTenant implements Scope
 *     {
 *         public function __construct(private int $tenantId) {}
 *         public function apply(EntityQuery $query): EntityQuery
 *         {
 *             return $query->where('tenantId', '=', $this->tenantId);
 *         }
 *     }
 *
 *     Entities::query(Task::class)->scoped(new InTenant($tenantId))->fetch($reader);
 *
 * Scopes also apply to related entities loaded with with() and to whereHas(). A query can
 * switch one off by class with withoutScope(); there is no switch for all of them.
 */
interface Scope
{
    /**
     * @template T of object
     * @param EntityQuery<T> $query
     * @return EntityQuery<T>
     */
    public function apply(EntityQuery $query): EntityQuery;
}
