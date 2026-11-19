<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use ReflectionClass;
use Shirakaba\Orm\Attribute\SoftDelete;

/**
 * The default scope of #[SoftDelete] entities: rows whose deletion time is NULL. Switch it
 * off with withoutScope(NotDeleted::class) to see deleted rows too.
 */
final readonly class NotDeleted implements Scope
{
    public function apply(EntityQuery $query): EntityQuery
    {
        $soft = (new ReflectionClass($query->entityClass())->getAttributes(SoftDelete::class)[0] ?? null)?->newInstance();

        return $soft === null ? $query : $query->whereNull($soft->property);
    }
}
