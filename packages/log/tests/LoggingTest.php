<?php

declare(strict_types=1);

namespace Shirakaba\Log\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Time\FrozenClock;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Core\Testing\TestEvent;
use Shirakaba\Log\LoggingObserver;
use Shirakaba\Log\StreamLogger;
use Psr\Log\LogLevel;
use RuntimeException;

#[CoversClass(LoggingObserver::class)]
#[CoversClass(StreamLogger::class)]
final class LoggingTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        rewind($this->stream);
        $records = [];
        foreach (explode("\n", trim((string) stream_get_contents($this->stream))) as $line) {
            if ($line !== '') {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($record);
                /** @var array<string, mixed> $record */
                $records[] = $record;
            }
        }

        return $records;
    }

    private function client(bool $accessLog = false, string $minimumLevel = LogLevel::DEBUG): TestClient
    {
        $time = 10.0;
        $app = new AppBuilder();
        $app->observe(new LoggingObserver(new StreamLogger($this->stream, $minimumLevel, new FrozenClock('2026-11-11T09:30:00.25+09:00')), $accessLog, static function () use (&$time): float {
            $time += 0.0125;

            return $time;
        }));
        $app->get('/users/{id}', static fn (#[Path] int $id): Text => $id === 0 ? throw HttpFailure::notFound('No user 0.') : new Text('user'));
        $app->get('/boom', static fn (): Text => throw new LogicException('database password was wrong', previous: new RuntimeException('connection refused')));
        $app->get('/cleanup', static function (RequestScope $scope): Text {
            $scope->defer(static fn () => throw new RuntimeException('lock not released'));

            return new Text('ok');
        });

        return new TestClient($app->build());
    }

    public function testUnexpectedErrorsAreLoggedWithTheException(): void
    {
        $response = $this->client()->get('/boom');

        self::assertStringNotContainsString('password', (string) $response->getBody(), 'The response stays bare (design/05-errors-and-observability.md).');
        $record = $this->records()[0] ?? [];
        self::assertSame('error', $record['level'] ?? null);
        self::assertSame('2026-11-11T00:30:00.250000Z', $record['time'] ?? null, 'Log times are UTC whatever the default timezone.');
        self::assertSame('Request failed on GET /boom: LogicException: database password was wrong', $record['message'] ?? null);
        $context = $record['context'] ?? null;
        self::assertIsArray($context);
        $exception = $context['exception'] ?? null;
        self::assertIsArray($exception);
        self::assertSame('LogicException', $exception['class'] ?? null);
        self::assertIsArray($exception['previous'] ?? null);
        self::assertSame('connection refused', $exception['previous']['message'] ?? null);
    }

    public function testClientErrorsAreDebugAndCleanupFailuresWarnings(): void
    {
        $client = $this->client();
        $client->get('/users/0');
        $client->get('/cleanup');

        self::assertSame(
            [['debug', 'Client error 404 on GET /users/{id}: No user 0.'], ['warning', 'A request cleanup callback failed: RuntimeException: lock not released']],
            array_map(static fn (array $record): array => [$record['level'] ?? null, $record['message'] ?? null], $this->records()),
        );
    }

    public function testAccessLogUsesRouteTemplates(): void
    {
        $client = $this->client(accessLog: true, minimumLevel: LogLevel::INFO);
        $client->get('/users/42');
        $client->get('/nowhere');

        self::assertSame(
            ['GET /users/{id} 200 12.5ms', 'GET (unmatched) 404 12.5ms'],
            array_map(static fn (array $record): mixed => $record['message'] ?? null, $this->records()),
        );
    }

    public function testAccessLogSummarisesRecordedEvents(): void
    {
        $app = new AppBuilder();
        $app->observe(new LoggingObserver(new StreamLogger($this->stream, LogLevel::INFO), accessLog: true, clock: static fn (): float => 0.0));
        $app->get('/', static function (RequestScope $scope): Text {
            $scope->record(new TestEvent('db.statement', 0.002));
            $scope->record(new TestEvent('db.statement', 0.003));

            return new Text('ok');
        });
        new TestClient($app->build())->get('/');
        $record = $this->records()[0] ?? [];

        self::assertSame('GET / 200 0ms db.statement=2', $record['message'] ?? null);
        $context = $record['context'] ?? null;
        self::assertIsArray($context);
        self::assertSame(2, $context['db.statement.count'] ?? null);
        self::assertEquals(5.0, $context['db.statement.duration_ms'] ?? null, 'JSON writes 5.0 as 5.');
    }

    public function testRejectsUnknownLevels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StreamLogger($this->stream, 'loud');
    }
}
