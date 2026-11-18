<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Json\JsonMapper;
use Throwable;

/**
 * Runs jobs: reserves one, decodes it, calls its handler, and acknowledges, retries or
 * fails it (design/10-background-work.md). The worker command and tests share it.
 *
 * Each job runs in its own RequestScope (ScopedWork), so request-scoped services such as a
 * database connection are leased for the job and released after it, and the observers see
 * it like a request: method "JOB", route the job's type name, status 200 when it succeeded,
 * 503 when it will be retried and 500 when it failed for good. The trace continues the
 * request that pushed the job.
 */
final readonly class JobRunner
{
    /**
     * @param list<Observer> $observers
     */
    public function __construct(
        private Jobs $jobs,
        private RetryPolicy $retries = new RetryPolicy(),
        private array $observers = [],
    ) {}

    /**
     * The queue jobs are taken from.
     */
    public function queue(): Queue
    {
        return $this->jobs->queue;
    }

    /**
     * Runs the next due job, if any.
     *
     * @param non-empty-list<string> $queues
     * @param positive-int $leaseSeconds longer than any job takes; an expired lease lets
     *                                   another worker run the job again
     * @return array{ReservedJob, JobOutcome, ?Throwable}|null null when no job was due
     * @phpstan-impure io.db
     */
    public function runNext(array $queues = ['default'], int $leaseSeconds = 300, Cancellation $cancellation = new Cancellation()): ?array
    {
        $queue = $this->jobs->queue;
        $job = $queue->reserve($queues, $leaseSeconds);
        if ($job === null) {
            return null;
        }

        $class = $this->jobs->classOf($job->type);
        $handler = $class === null ? null : $this->jobs->handlerOf($class);
        if ($class === null || $handler === null) {
            // Never run something this code does not know, whatever the payload says.
            $queue->fail($job, sprintf('Unknown job type "%s".', $job->type));

            return [$job, JobOutcome::Failed, null];
        }
        try {
            $payload = JsonMapper::decode($job->payload, $class);
        } catch (Throwable $e) {
            $queue->fail($job, sprintf('Undecodable payload: %s', $e->getMessage()));

            return [$job, JobOutcome::Failed, $e];
        }

        $retryable = $job->attempt < $job->maxAttempts;
        $e = ScopedWork::run(
            $this->observers,
            'JOB',
            $job->type,
            $cancellation,
            static function (RequestScope $scope) use ($handler, $payload): void {
                $handler($payload, $scope);
            },
            $job->traceparent === null ? [] : ['traceparent' => $job->traceparent],
            static fn (?Throwable $error): int => $error === null ? 200 : ($retryable && !$error instanceof JobFailed ? 503 : 500),
        );
        if ($e !== null) {
            $error = sprintf('%s: %s', $e::class, $e->getMessage());
            if ($retryable && !$e instanceof JobFailed) {
                $released = $queue->release($job, $this->retries->delayAfter($job->attempt));

                return [$job, $released ? JobOutcome::Retried : JobOutcome::Lost, $e];
            }
            $failed = $queue->fail($job, $error);

            return [$job, $failed ? JobOutcome::Failed : JobOutcome::Lost, $e];
        }

        return [$job, $queue->acknowledge($job) ? JobOutcome::Succeeded : JobOutcome::Lost, null];
    }

    /**
     * Runs due jobs until none is left or $limit ran, for tests and scripts.
     *
     * @param non-empty-list<string> $queues
     * @return int how many jobs ran
     * @phpstan-impure io.db
     */
    public function runPending(array $queues = ['default'], int $limit = 1000): int
    {
        $ran = 0;
        while ($ran < $limit && $this->runNext($queues) !== null) {
            $ran++;
        }

        return $ran;
    }
}
