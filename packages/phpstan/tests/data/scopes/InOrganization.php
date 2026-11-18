<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Scopes;

use Shirakaba\Orm\EntityQuery;
use Shirakaba\Orm\Scope;

final readonly class InOrganization implements Scope
{
    public function __construct(
        private int $organization,
    ) {}

    public function apply(EntityQuery $query): EntityQuery
    {
        return $query->where('organizationId', '=', $this->organization);
    }
}
