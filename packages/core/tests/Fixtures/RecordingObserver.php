<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Throwable;

/**
 * Records every event as a string, for asserting event order.
 */
final class RecordingObserver implements Observer
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly bool $explode = false,
    ) {}

    public function begin(RequestSummary $request): Observation
    {
        $this->events[] = sprintf('begin %s HTTP/%s', $request->method, $request->protocolVersion);

        return new class($this) implements Observation {
            public function __construct(private readonly RecordingObserver $observer) {}

            public function matched(RouteSummary $route): void
            {
                $this->observer->record(sprintf('matched %s %s %s', $route->method, $route->template, $route->name ?? '-'));
            }

            public function handlerFinished(): void
            {
                $this->observer->record('handlerFinished');
            }

            public function failed(Throwable $error): void
            {
                $this->observer->record('failed ' . $error::class . ': ' . $error->getMessage());
            }

            public function cleanupFailed(Throwable $error): void
            {
                $this->observer->record('cleanupFailed ' . $error->getMessage());
            }

            public function recorded(Event $event): void
            {
                $this->observer->record('recorded ' . $event->name());
            }

            public function responseReady(int $status): void
            {
                $this->observer->record('responseReady ' . $status);
            }
        };
    }

    public function record(string $event): void
    {
        $this->events[] = $event;
        if ($this->explode) {
            throw new \RuntimeException('observer failure');
        }
    }
}
