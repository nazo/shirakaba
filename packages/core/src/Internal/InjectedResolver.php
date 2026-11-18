<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;

/**
 * Passes the request or its scope to a handler.
 *
 * @internal
 */
final readonly class InjectedResolver implements ArgumentResolver
{
    public function __construct(
        private bool $scope,
    ) {}

    public function resolve(Exchange $exchange): mixed
    {
        return $this->scope ? $exchange->scope : $exchange->request;
    }
}
