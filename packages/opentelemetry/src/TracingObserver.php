<?php

declare(strict_types=1);

namespace Shirakaba\OpenTelemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Throwable;

/**
 * One server span per request, following the OpenTelemetry HTTP semantic conventions
 * (design/05-errors-and-observability.md):
 *
 *     $app->observe(new TracingObserver($tracerProvider->getTracer('my-api')));
 *
 * The span continues the caller's trace from the W3C propagation headers, is named after
 * the route template ("GET /users/{id}"), never the raw path, records exceptions, and is
 * marked as an error for 5xx responses only. While the request runs, the span's context is
 * active, so instrumentation inside handlers (databases, HTTP clients) creates children of
 * it; it is detached when the response is ready.
 *
 * Events recorded through RequestScope::record() become span events with their
 * attributes, and the span gets `shirakaba.<name>.count` and `shirakaba.<name>.duration` totals.
 */
final readonly class TracingObserver implements Observer
{
    private TextMapPropagatorInterface $propagator;

    public function __construct(
        private TracerInterface $tracer,
        ?TextMapPropagatorInterface $propagator = null,
    ) {
        $this->propagator = $propagator ?? TraceContextPropagator::getInstance();
    }

    public function begin(RequestSummary $request): Observation
    {
        // Methods are validated tokens, so never empty; the fallback satisfies the API's type.
        $span = $this->tracer
            ->spanBuilder($request->method !== '' ? $request->method : 'HTTP')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($this->propagator->extract($request->propagation))
            ->setAttribute('http.request.method', $request->method)
            ->setAttribute('network.protocol.version', $request->protocolVersion)
            ->startSpan();

        return new class($span, $span->activate()) implements Observation {
            /** @var array<string, array{int, float}> event name => count, seconds */
            private array $events = [];

            public function __construct(
                private readonly SpanInterface $span,
                private readonly ScopeInterface $scope,
            ) {}

            public function matched(RouteSummary $route): void
            {
                $this->span->updateName($route->method . ' ' . $route->template);
                $this->span->setAttribute('http.route', $route->template);
            }

            public function handlerFinished(): void {}

            public function failed(Throwable $error): void
            {
                $this->span->recordException($error);
                if (!$error instanceof HttpFailure) {
                    $this->span->setAttribute('error.type', $error::class);
                }
            }

            public function cleanupFailed(Throwable $error): void
            {
                $this->span->recordException($error, ['shirakaba.cleanup' => true]);
            }

            public function recorded(Event $event): void
            {
                $duration = $event->duration();
                $attributes = $event->attributes();
                if ($duration !== null) {
                    $attributes['duration'] = $duration;
                }
                $this->span->addEvent($event->name(), $attributes);
                [$count, $seconds] = $this->events[$event->name()] ?? [0, 0.0];
                $this->events[$event->name()] = [$count + 1, $seconds + ($duration ?? 0.0)];
            }

            public function responseReady(int $status): void
            {
                $this->span->setAttribute('http.response.status_code', $status);
                foreach ($this->events as $name => [$count, $seconds]) {
                    $this->span->setAttribute('shirakaba.' . $name . '.count', $count);
                    $this->span->setAttribute('shirakaba.' . $name . '.duration', $seconds);
                }
                if ($status >= 500) {
                    $this->span->setStatus(StatusCode::STATUS_ERROR);
                }
                $this->scope->detach();
                $this->span->end();
            }
        };
    }
}
