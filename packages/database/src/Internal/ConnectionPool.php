<?php

declare(strict_types=1);

namespace Shirakaba\Database\Internal;

use PDO;
use Shirakaba\Database\Dialect\Dialect;
use Throwable;

/**
 * The idle connection a Database keeps between requests when reuse is on. One
 * request uses one connection at a time, and worker runtimes run one request after another
 * per process, so one idle connection per process is enough.
 *
 * @internal
 */
final class ConnectionPool
{
    /** @var array{PDO, Dialect, int|float, int|float, int}|null connection, dialect, opened, idle since (hrtime), uses */
    private ?array $idle = null;

    public function __construct(
        private readonly float $maxIdleSeconds,
        private readonly float $maxLifetimeSeconds,
        private readonly int $maxUses,
        private readonly float $checkAfterSeconds = 10.0,
    ) {}

    /**
     * An idle connection that is still usable, or null.
     *
     * @return array{PDO, Dialect, int|float, int}|null connection, dialect, opened, uses so far
     */
    public function take(): ?array
    {
        $idle = $this->idle;
        $this->idle = null;
        if ($idle === null) {
            return null;
        }
        [$pdo, $dialect, $opened, $since, $uses] = $idle;
        $now = hrtime(true);
        $idleSeconds = ($now - $since) / 1e9;
        if ($idleSeconds > $this->maxIdleSeconds || (($now - $opened) / 1e9) > $this->maxLifetimeSeconds) {
            return null;
        }
        if ($idleSeconds > $this->checkAfterSeconds) {
            // The server may have closed it; a cheap round trip outside the statement log.
            try {
                $pdo->query('SELECT 1');
            } catch (Throwable) {
                return null;
            }
        }

        return [$pdo, $dialect, $opened, $uses];
    }

    /**
     * Resets a connection a request has finished with and keeps it; drops it when it has
     * served its uses or cannot be reset.
     */
    public function give(PDO $pdo, Dialect $dialect, int|float $opened, int $uses): void
    {
        if ($uses >= $this->maxUses) {
            return;
        }
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $dialect->reset($pdo);
        } catch (Throwable) {
            return;
        }
        $this->idle = [$pdo, $dialect, $opened, hrtime(true), $uses];
    }
}
