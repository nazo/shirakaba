<?php

declare(strict_types=1);

namespace Shirakaba\Log;

use Closure;
use Psr\Log\LoggerInterface;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Throwable;

/**
 * Writes what the core reports to a PSR-3 logger (design/05-errors-and-observability.md):
 *
 * - unexpected exceptions and 5xx failures as `error`, with the exception in the context;
 * - 4xx failures as `debug`, since they are the client's mistakes;
 * - failed cleanup callbacks as `warning`;
 * - optionally one `info` access log line per request, with the route template rather
 *   than the raw path (design/05-errors-and-observability.md), and the count and total time of recorded events by
 *   name, such as database statements.
 *
 *     $app->observe(new LoggingObserver($logger, accessLog: true));
 */
final readonly class LoggingObserver implements Observer
{
    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param (Closure(): float)|null $clock monotonic seconds; defaults to hrtime()
     */
    public function __construct(
        private LoggerInterface $logger,
        private bool $accessLog = false,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function begin(RequestSummary $request): Observation
    {
        return new class($this->logger, $this->accessLog, $this->clock, $request) implements Observation {
            private ?RouteSummary $route = null;
            private float $started;

            /** @var array<string, array{int, float}> event name => count, seconds */
            private array $events = [];

            /**
             * @param Closure(): float $clock
             */
            public function __construct(
                private readonly LoggerInterface $logger,
                private readonly bool $accessLog,
                private readonly Closure $clock,
                private readonly RequestSummary $request,
            ) {
                $this->started = ($this->clock)();
            }

            public function matched(RouteSummary $route): void
            {
                $this->route = $route;
            }

            public function handlerFinished(): void {}

            public function failed(Throwable $error): void
            {
                $context = ['exception' => $error, 'method' => $this->request->method, 'route' => $this->route?->template];
                if ($error instanceof HttpFailure && $error->status < 500) {
                    $this->logger->debug('Client error {status} on {method} {route}: {detail}', [
                        ...$context,
                        'status' => $error->status,
                        'detail' => $error->detail,
                    ]);

                    return;
                }

                $this->logger->error('Request failed on {method} {route}: {class}: {message}', [
                    ...$context,
                    'class' => $error::class,
                    'message' => $error->getMessage(),
                ]);
            }

            public function recorded(Event $event): void
            {
                [$count, $seconds] = $this->events[$event->name()] ?? [0, 0.0];
                $this->events[$event->name()] = [$count + 1, $seconds + ($event->duration() ?? 0.0)];
            }

            public function cleanupFailed(Throwable $error): void
            {
                $this->logger->warning('A request cleanup callback failed: {class}: {message}', [
                    'exception' => $error,
                    'class' => $error::class,
                    'message' => $error->getMessage(),
                ]);
            }

            public function responseReady(int $status): void
            {
                if (!$this->accessLog) {
                    return;
                }
                $context = [
                    'method' => $this->request->method,
                    'route' => $this->route->template ?? '(unmatched)',
                    'status' => $status,
                    'duration_ms' => round((($this->clock)() - $this->started) * 1000, 1),
                ];
                $summary = '';
                foreach ($this->events as $name => [$count, $seconds]) {
                    $context[$name . '.count'] = $count;
                    $context[$name . '.duration_ms'] = round($seconds * 1000, 1);
                    $summary .= sprintf(' %s=%d', $name, $count);
                }
                $this->logger->info('{method} {route} {status} {duration_ms}ms' . $summary, $context);
            }
        };
    }
}
