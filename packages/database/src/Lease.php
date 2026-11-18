<?php

declare(strict_types=1);

namespace Shirakaba\Database;

use Closure;

/**
 * A connection borrowed from a Database (design/08-database.md). Whoever leases it must release it, which
 * rolls back anything left open, resets the session and gives the connection back to the
 * pool (design/08-database.md) or closes it. After release() the connection refuses statements, so a
 * Reader or Writer kept by mistake cannot reach a connection serving someone else.
 *
 *     $lease = $database->lease();
 *     try {
 *         $lease->connection->writer()->execute('...');
 *     } finally {
 *         $lease->release();
 *     }
 */
final class Lease
{
    private bool $released = false;

    /**
     * @param Closure(): void $return gives the connection back
     * @internal created by Database::lease()
     */
    public function __construct(
        public readonly Connection $connection,
        private readonly Closure $return,
    ) {}

    /**
     * Releases the connection; calling it again does nothing.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        ($this->return)();
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
