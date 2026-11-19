<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Tests\Fixtures;

use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Throwable;

/**
 * Keeps the events of every observed request or job.
 */
final class EventRecorder implements Observer
{
    /** @var list<Event> */
    public array $events = [];

    public function begin(RequestSummary $request): Observation
    {
        return new class($this) implements Observation {
            public function __construct(private readonly EventRecorder $recorder) {}

            public function matched(RouteSummary $route): void {}

            public function handlerFinished(): void {}

            public function failed(Throwable $error): void {}

            public function cleanupFailed(Throwable $error): void {}

            public function recorded(Event $event): void
            {
                $this->recorder->events[] = $event;
            }

            public function responseReady(int $status): void {}
        };
    }
}
