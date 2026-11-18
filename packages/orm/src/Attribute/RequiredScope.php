<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * A scope every query of the entity must receive with scoped(), or switch off by name with
 * withoutScope(). Use it for tenants: forgetting the tenant fails instead of leaking rows.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RequiredScope
{
    /**
     * @param class-string<\Shirakaba\Orm\Scope> $scope
     */
    public function __construct(
        public string $scope,
    ) {}
}
