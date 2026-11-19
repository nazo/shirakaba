<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use Fiber;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Internal\MiddlewareNode;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\Deadline;
use Shirakaba\Core\Scope\RequestCancelled;
use Shirakaba\Core\Scope\RequestOptions;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Core\Tests\Fixtures\EventLog;
use Shirakaba\Core\Tests\Fixtures\RecordingObserver;
use Shirakaba\Http\Message\ServerRequest;
use stdClass;

#[CoversClass(RequestScope::class)]
#[CoversClass(Cancellation::class)]
#[CoversClass(Deadline::class)]
#[CoversClass(MiddlewareNode::class)]
final class RequestScopeTest extends TestCase
{
    public function testCleanupRunsInReverseOrderAndSurvivesFailures(): void
    {
        $log = new EventLog();
        $observer = new RecordingObserver();
        $app = new AppBuilder();
        $app->observe($observer);
        $app->get('/', static function (RequestScope $scope) use ($log): Text {
            $scope->defer(static function () use ($log): void {
                $log->add('first');
            });
            $scope->defer(static function () use ($log): void {
                $log->add('second');

                throw new LogicException('cleanup broke');
            });
            $scope->defer(static function () use ($log): void {
                $log->add('third');
            });

            return new Text('ok');
        });

        $response = new TestClient($app->build())->get('/');

        self::assertSame('ok', (string) $response->getBody());
        self::assertSame(['third', 'second', 'first'], $log->entries);
        self::assertContains('cleanupFailed cleanup broke', $observer->events);
    }

    public function testCleanupRunsWhenTheHandlerFails(): void
    {
        $log = new EventLog();
        $app = new AppBuilder();
        $app->get('/', static function (RequestScope $scope) use ($log): Text {
            $scope->defer(static function () use ($log): void {
                $log->add('released');
            });

            throw new LogicException('handler broke');
        });

        $response = new TestClient($app->build())->get('/');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['released'], $log->entries);
    }

    public function testMiddlewareRegistersValuesForHandlers(): void
    {
        $principal = new stdClass();
        $principal->name = 'alice';
        $app = new AppBuilder();
        $app->middleware(new readonly class($principal) implements MiddlewareInterface {
            public function __construct(private stdClass $principal) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                RequestScope::of($request)->set(clone $this->principal);

                return $handler->handle($request);
            }
        });
        $app->get('/', static function (RequestScope $scope): Text {
            $name = $scope->get(stdClass::class)->name ?? null;

            return new Text(is_string($name) ? $name : '?');
        });

        self::assertSame('alice', (string) new TestClient($app->build())->get('/')->getBody());
    }

    public function testFindAndGetOnMissingValues(): void
    {
        $scope = $this->scopeFromHandler();

        self::assertNull($scope->find(stdClass::class));
        $this->expectException(LogicException::class);
        $scope->get(stdClass::class);
    }

    public function testScopeIsClosedAfterTheResponse(): void
    {
        $scope = $this->scopeFromHandler();

        $this->expectExceptionMessage('closed');
        $scope->defer(static function (): void {});
    }

    public function testRequestWithoutApplicationHasNoScope(): void
    {
        $this->expectException(LogicException::class);
        RequestScope::of(new ServerRequest('GET', '/'));
    }

    public function testCancellationAndDeadlineComeFromTheRuntime(): void
    {
        $app = new AppBuilder();
        $app->get('/', static function (RequestScope $scope): Text {
            $scope->deadline()?->throwIfExpired();
            $scope->cancellation()->throwIfRequested();

            return new Text('ran');
        });
        $application = $app->build();

        $cancellation = new Cancellation();
        $cancellation->request('client went away');
        $cancelled = $application->handleWith(new ServerRequest('GET', '/'), new RequestOptions($cancellation));
        $expired = $application->handleWith(new ServerRequest('GET', '/'), new RequestOptions(deadline: Deadline::in(-1)));
        $normal = $application->handle(new ServerRequest('GET', '/'));

        self::assertSame(500, $cancelled->getStatusCode());
        self::assertSame(503, $expired->getStatusCode());
        self::assertSame('ran', (string) $normal->getBody());
    }

    public function testDeadlineReportsRemainingTime(): void
    {
        $deadline = Deadline::in(60);

        self::assertFalse($deadline->isExpired());
        self::assertGreaterThan(59.0, $deadline->remaining());
        self::assertSame(0.0, Deadline::in(-1)->remaining());
    }

    public function testCancellationKeepsTheFirstReason(): void
    {
        $cancellation = new Cancellation();
        $cancellation->request('first');
        $cancellation->request('second');

        $this->expectException(RequestCancelled::class);
        $this->expectExceptionMessage('first');
        $cancellation->throwIfRequested();
    }

    /**
     * Two requests interleaved on one application, as a coroutine runtime would run them,
     * must not see each other's state.
     */
    public function testInterleavedRequestsAreIsolated(): void
    {
        $app = new AppBuilder();
        $app->middleware(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $id = new stdClass();
                $id->value = $request->getHeaderLine('X-User');
                RequestScope::of($request)->set($id);
                Fiber::suspend();

                return $handler->handle($request);
            }
        });
        $app->get('/users/{id}', static function (#[Path] int $id, RequestScope $scope): Text {
            Fiber::suspend();
            $user = $scope->get(stdClass::class)->value ?? null;

            return new Text(sprintf('%d:%s', $id, is_string($user) ? $user : '?'));
        });
        $application = $app->build();

        $fibers = [];
        foreach (['1' => 'alice', '2' => 'bob'] as $id => $user) {
            $fibers[$id] = new Fiber(static fn (): ResponseInterface => $application->handle(
                new ServerRequest('GET', '/users/' . $id, ['X-User' => $user]),
            ));
        }
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        while (array_filter($fibers, static fn (Fiber $fiber): bool => !$fiber->isTerminated()) !== []) {
            foreach (array_reverse($fibers, true) as $fiber) {
                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                }
            }
        }

        $bodies = array_map(static function (Fiber $fiber): string {
            $response = $fiber->getReturn();

            return $response instanceof ResponseInterface ? (string) $response->getBody() : '';
        }, $fibers);
        self::assertSame(['1' => '1:alice', '2' => '2:bob'], $bodies);
    }

    public function testHttpFailureFromScopeHelpersIsRendered(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => throw new HttpFailure(503, 'Busy.'));

        self::assertSame(503, new TestClient($app->build())->get('/')->getStatusCode());
    }

    private function scopeFromHandler(): RequestScope
    {
        $captured = new class {
            public ?RequestScope $scope = null;
        };
        $app = new AppBuilder();
        $app->get('/', static function (RequestScope $scope) use ($captured): Text {
            $captured->scope = $scope;

            return new Text('');
        });
        new TestClient($app->build())->get('/');
        $scope = $captured->scope;
        self::assertInstanceOf(RequestScope::class, $scope);

        return $scope;
    }
}
