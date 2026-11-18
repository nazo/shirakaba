<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Testing;

use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Shirakaba\Database\StatementExecuted;
use Shirakaba\DatabaseBridge\RecordedDatabaseEvent;
use Throwable;

/**
 * Collects the statements of each request, for tests that bound how many a request runs
 *, which catches N+1 queries in any layer:
 *
 *     $log = new StatementLog();
 *     $app->observe($log);
 *     $client->get('/posts');
 *     self::assertLessThanOrEqual(3, count($log->last()), $log->describe());
 */
final class StatementLog implements Observer
{
    /** @var list<list<string>> SQL of each request, oldest first */
    private array $requests = [];

    public function begin(RequestSummary $request): Observation
    {
        $index = count($this->requests);
        $this->requests[] = [];

        return new class ($this, $index) implements Observation {
            public function __construct(
                private readonly StatementLog $log,
                private readonly int $index,
            ) {}

            public function matched(RouteSummary $route): void {}

            public function handlerFinished(): void {}

            public function failed(Throwable $error): void {}

            public function cleanupFailed(Throwable $error): void {}

            public function recorded(Event $event): void
            {
                if ($event instanceof RecordedDatabaseEvent && $event->event instanceof StatementExecuted) {
                    $this->log->add($this->index, $event->event->sql);
                }
            }

            public function responseReady(int $status): void {}
        };
    }

    /**
     * @internal
     */
    public function add(int $request, string $sql): void
    {
        $this->requests[$request][] = $sql;
    }

    /**
     * The statements of the most recent request.
     *
     * @return list<string>
     */
    public function last(): array
    {
        $last = array_key_last($this->requests);

        return $last === null ? [] : $this->requests[$last];
    }

    /**
     * Statements of the most recent request that ran more than once, with their counts:
     * the signature of an N+1 query.
     *
     * @return array<string, int>
     */
    public function repeated(): array
    {
        return array_filter(array_count_values($this->last()), static fn (int $count): bool => $count > 1);
    }

    /**
     * The last request's statements, numbered, for assertion messages.
     */
    public function describe(): string
    {
        $lines = [];
        foreach ($this->last() as $index => $sql) {
            $lines[] = sprintf('%d. %s', $index + 1, $sql);
        }

        return "Statements of the last request:\n" . implode("\n", $lines);
    }

    public function clear(): void
    {
        $this->requests = [];
    }
}
