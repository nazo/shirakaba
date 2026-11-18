<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * A scope applied to every query of the entity unless the query calls withoutScope().
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class DefaultScope
{
    /**
     * @param class-string<\Shirakaba\Orm\Scope> $scope
     */
    public function __construct(
        public string $scope,
    ) {}
}
