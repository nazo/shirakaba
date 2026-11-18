<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

/**
 * The principal of the current request, as registered in the RequestScope.
 */
final readonly class Authentication
{
    public function __construct(
        public object $principal,
    ) {}
}
