<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Shirakaba\Core\Scope\Cancellation;

/**
 * A queue that can tell an idle worker when a job arrives, instead of the worker polling
 *. DatabaseQueue does on PostgreSQL with LISTEN/NOTIFY.
 */
interface WaitsForJobs
{
    /**
     * Returns when a job may have been pushed to one of the queues, after $seconds, or when
     * the cancellation is requested, whichever comes first.
     *
     * @param list<string> $queues
     */
    public function waitForJobs(array $queues, float $seconds, Cancellation $cancellation): void;
}
