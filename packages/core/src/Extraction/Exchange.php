<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Shirakaba\Core\Scope\RequestScope;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a resolver sees of the request being handled.
 */
final readonly class Exchange
{
    /**
     * @param array<string, string> $routeParameters decoded path parameters by name
     */
    public function __construct(
        public ServerRequestInterface $request,
        public array $routeParameters,
        public RequestScope $scope,
    ) {}
}
