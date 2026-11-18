<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Tests\Fixtures;

use Shirakaba\Orm\EntityQuery;
use Shirakaba\Orm\Scope;
use Shirakaba\Orm\ScopeOnInsert;

final readonly class InTeam implements Scope, ScopeOnInsert
{
    public function __construct(
        private int $team,
    ) {}

    public function apply(EntityQuery $query): EntityQuery
    {
        return $query->where('team', '=', $this->team);
    }

    public function admits(object $entity): bool
    {
        return property_exists($entity, 'team') && $entity->team === $this->team;
    }
}
