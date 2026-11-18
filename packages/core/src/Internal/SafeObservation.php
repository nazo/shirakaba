<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Closure;
use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Throwable;

/**
 * Forwards events to every observer's observation, so that nothing an observer throws can
 * affect the response or the other observers. Without observers it does nothing at all.
 *
 * @internal
 */
final readonly class SafeObservation implements Observation
{
    /**
     * @param list<Observation> $observations
     */
    private function __construct(
        private array $observations,
    ) {}

    /**
     * @param list<Observer> $observers
     */
    public static function begin(array $observers, RequestSummary $request): self
    {
        $observations = [];
        foreach ($observers as $observer) {
            try {
                $observations[] = $observer->begin($request);
            } catch (Throwable) {
                // An observer that cannot start is skipped for this request.
            }
        }

        return new self($observations);
    }

    public function matched(RouteSummary $route): void
    {
        $this->each(static fn (Observation $observation) => $observation->matched($route));
    }

    public function handlerFinished(): void
    {
        $this->each(static fn (Observation $observation) => $observation->handlerFinished());
    }

    public function failed(Throwable $error): void
    {
        $this->each(static fn (Observation $observation) => $observation->failed($error));
    }

    public function cleanupFailed(Throwable $error): void
    {
        $this->each(static fn (Observation $observation) => $observation->cleanupFailed($error));
    }

    public function recorded(Event $event): void
    {
        $this->each(static fn (Observation $observation) => $observation->recorded($event));
    }

    public function responseReady(int $status): void
    {
        $this->each(static fn (Observation $observation) => $observation->responseReady($status));
    }

    /**
     * @param Closure(Observation): void $event
     */
    private function each(Closure $event): void
    {
        foreach ($this->observations as $observation) {
            try {
                $event($observation);
            } catch (Throwable) {
                // Observers never change the response (design/05-errors-and-observability.md).
            }
        }
    }
}
