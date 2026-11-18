<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

/**
 * Cooperative cancellation of one request (design/03-runtimes-and-scopes.md).
 *
 * A runtime keeps a reference and calls request() when the client goes away; request code
 * checks it between steps. Nothing interrupts a blocking call.
 */
final class Cancellation
{
    private ?string $reason = null;

    public function request(string $reason = 'The request was cancelled.'): void
    {
        $this->reason ??= $reason;
    }

    public function isRequested(): bool
    {
        return $this->reason !== null;
    }

    /**
     * @throws RequestCancelled when cancellation has been requested
     */
    public function throwIfRequested(): void
    {
        if ($this->reason !== null) {
            throw new RequestCancelled($this->reason);
        }
    }
}
