<?php

declare(strict_types=1);

namespace Shirakaba\OpenTelemetry\Tests;

use ArrayObject;
use LogicException;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\OpenTelemetry\TracingObserver;

#[CoversClass(TracingObserver::class)]
final class TracingObserverTest extends TestCase
{
    /** @var ArrayObject<int, mixed> */
    private ArrayObject $spans;
    private TracerProvider $provider;

    protected function setUp(): void
    {
        $this->spans = new ArrayObject();
        $this->provider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->spans)));
    }

    private function client(): TestClient
    {
        $tracer = $this->provider->getTracer('shirakaba-test');
        $app = new AppBuilder();
        $app->observe(new TracingObserver($tracer));
        $app->get('/users/{id}', static function (#[Path] int $id) use ($tracer): Text {
            // Instrumentation inside the handler becomes a child of the request span.
            $tracer->spanBuilder('load user')->startSpan()->end();

            return $id === 0 ? throw HttpFailure::notFound() : new Text('user');
        });
        $app->get('/boom', static fn (): Text => throw new LogicException('boom'));

        return new TestClient($app->build());
    }

    private function span(int $index): ImmutableSpan
    {
        $span = $this->spans[$index] ?? null;
        self::assertInstanceOf(ImmutableSpan::class, $span);

        return $span;
    }

    public function testCreatesAServerSpanNamedAfterTheRoute(): void
    {
        $this->client()->get('/users/42?secret=1');

        $child = $this->span(0);
        $server = $this->span(1);
        self::assertSame('GET /users/{id}', $server->getName());
        self::assertSame(SpanKind::KIND_SERVER, $server->getKind());
        self::assertSame('/users/{id}', $server->getAttributes()->get('http.route'));
        self::assertSame(200, $server->getAttributes()->get('http.response.status_code'));
        self::assertSame($server->getSpanId(), $child->getParentSpanId(), 'Spans created by the handler are children.');
        self::assertStringNotContainsString('secret', (string) json_encode($server->getAttributes()->toArray()));
    }

    public function testContinuesTheCallersTrace(): void
    {
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $this->client()->get('/users/1', ['traceparent' => "00-{$traceId}-00f067aa0ba902b7-01"]);

        self::assertSame($traceId, $this->span(1)->getTraceId());
        self::assertSame('00f067aa0ba902b7', $this->span(1)->getParentSpanId());
    }

    public function testErrorsOnlyFor5xx(): void
    {
        $client = $this->client();
        $client->get('/users/0');
        $client->get('/boom');

        $notFound = $this->span(1);
        $boom = $this->span(2);
        self::assertSame(StatusCode::STATUS_UNSET, $notFound->getStatus()->getCode());
        self::assertCount(1, $notFound->getEvents(), 'The 404 is recorded as an exception event.');
        self::assertSame(StatusCode::STATUS_ERROR, $boom->getStatus()->getCode());
        self::assertSame(LogicException::class, $boom->getAttributes()->get('error.type'));
    }

    public function testRecordedEventsBecomeSpanEventsAndTotals(): void
    {
        $app = new AppBuilder();
        $app->observe(new TracingObserver($this->provider->getTracer('shirakaba-test')));
        $app->get('/', static function (\Shirakaba\Core\Scope\RequestScope $scope): Text {
            $scope->record(new \Shirakaba\Core\Testing\TestEvent('db.statement', 0.25, ['db.query.text' => 'SELECT 1']));
            $scope->record(new \Shirakaba\Core\Testing\TestEvent('db.statement', 0.5));

            return new Text('ok');
        });
        new TestClient($app->build())->get('/');
        $span = $this->span(0);

        self::assertSame(['db.statement', 'db.statement'], array_map(static fn ($event): string => $event->getName(), $span->getEvents()));
        self::assertSame('SELECT 1', ($span->getEvents()[0] ?? null)?->getAttributes()->get('db.query.text'));
        self::assertSame(2, $span->getAttributes()->get('shirakaba.db.statement.count'));
        self::assertSame(0.75, $span->getAttributes()->get('shirakaba.db.statement.duration'));
    }

    public function testUnmatchedRequestsKeepTheMethodAsName(): void
    {
        $this->client()->get('/nowhere');

        self::assertSame('GET', $this->span(0)->getName());
        self::assertNull($this->span(0)->getAttributes()->get('http.route'));
    }
}
