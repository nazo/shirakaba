<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

use Closure;

/**
 * A response body that keeps using request resources after the handler returned, and
 * therefore takes over closing the request scope (design/03-runtimes-and-scopes.md).
 *
 * The body must call the callback exactly once: when it reaches the end, is closed or
 * detached, or, as a last resort, when it is destroyed.
 */
interface ScopeOwningBody
{
    /**
     * @param Closure(): void $closeScope
     */
    public function adoptScope(Closure $closeScope): void;
}
