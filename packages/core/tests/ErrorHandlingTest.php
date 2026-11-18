<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Application;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\ProblemDetails;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Http\TranslatesFailures;
use Shirakaba\Core\Internal\SafeObservation;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Core\Tests\Fixtures\HtmlErrors;
use Shirakaba\Core\Tests\Fixtures\RecordingObserver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Problem Details (design/05-errors-and-observability.md) and observer events (design/05-errors-and-observability.md).
 */
#[CoversClass(ProblemDetails::class)]
#[CoversClass(HttpFailure::class)]
#[CoversClass(Application::class)]
#[CoversClass(SafeObservation::class)]
#[CoversClass(\Shirakaba\Core\Internal\ErrorResponder::class)]
#[CoversClass(\Shirakaba\Core\Http\Failure::class)]
final class ErrorHandlingTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function problem(ResponseInterface $response): array
    {
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $document = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    public function testUnexpectedExceptionIsABare500(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => throw new LogicException('secret database password'));

        $response = new TestClient($app->build())->get('/');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], self::problem($response));
        self::assertStringNotContainsString('secret', (string) $response->getBody());
    }

    public function testTranslatorsTurnLibraryExceptionsIntoFailures(): void
    {
        $translator = new class implements TranslatesFailures {
            public function translate(\Throwable $error): ?HttpFailure
            {
                return $error instanceof \DomainException ? new HttpFailure(404, $error->getMessage()) : null;
            }
        };
        $middleware = new class implements MiddlewareInterface, TranslatesFailures {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }

            public function translate(\Throwable $error): ?HttpFailure
            {
                return $error instanceof \UnderflowException ? new HttpFailure(409, 'Out of stock.') : null;
            }
        };
        $app = new AppBuilder();
        $app->translateFailures($translator);
        $app->middleware($middleware);
        $app->get('/missing', static fn (): Text => throw new \DomainException('No such widget.'));
        $app->get('/stock', static fn (): Text => throw new \UnderflowException('internal detail'));
        $app->get('/other', static fn (): Text => throw new LogicException('internal detail'));
        $app->get('/failure', static fn (): Text => throw new HttpFailure(418, 'Teapot.'));
        $client = new TestClient($app->build());

        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'No such widget.'], self::problem($client->get('/missing')));
        self::assertSame(['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Out of stock.'], self::problem($client->get('/stock')), 'Global middleware that translates is applied without registering it.');
        self::assertSame(500, $client->get('/other')->getStatusCode(), 'Untranslated exceptions stay a bare 500.');
        self::assertSame(418, $client->get('/failure')->getStatusCode(), 'An HttpFailure is not translated.');
    }

    public function testHttpFailureIsRenderedWithItsMembersAndHeaders(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => throw new HttpFailure(
            401,
            'The token has expired.',
            'https://example.com/problems/expired-token',
            'Expired token',
            ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
        ));

        $response = new TestClient($app->build())->get('/');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame([
            'type' => 'https://example.com/problems/expired-token',
            'title' => 'Expired token',
            'status' => 401,
            'detail' => 'The token has expired.',
        ], self::problem($response));
    }

    public function testExtensionMembersAreAddedWithoutOverridingRfcMembers(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => throw new HttpFailure(
            422,
            'Invalid fields.',
            extensions: ['errors' => [['pointer' => '/name', 'detail' => 'is required']], 'status' => 200, 'title' => 'x'],
        ));

        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Unprocessable Content',
            'status' => 422,
            'detail' => 'Invalid fields.',
            'errors' => [['pointer' => '/name', 'detail' => 'is required']],
        ], self::problem(new TestClient($app->build())->get('/')));
    }

    public function testCustomRendererHandlesEveryErrorPath(): void
    {
        $app = new AppBuilder();
        $app->renderErrorsWith(new HtmlErrors());
        $app->middleware(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $request->getUri()->getPath() === '/limited' ? throw new HttpFailure(429, 'Slow down.') : $handler->handle($request);
            }
        });
        $app->get('/boom', static fn (): Text => throw new LogicException('secret'));
        $app->get('/users/{id}', static fn (#[Path] int $id): Text => new Text('user'));
        $client = new TestClient($app->build());

        $notFound = $client->get('/missing');
        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $notFound->getHeaderLine('Content-Type'));

        $notAllowed = $client->post('/boom');
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $notAllowed->getHeaderLine('Allow'), 'Required headers survive a renderer that drops them.');

        $error = $client->get('/boom');
        self::assertSame('<h1>500</h1><p></p><p>GET</p>', (string) $error->getBody());

        self::assertStringContainsString('expected an integer', (string) $client->get('/users/x')->getBody());
        self::assertSame('<h1>429</h1><p>Slow down.</p><p>GET</p>', (string) $client->get('/limited')->getBody());
    }

    public function testNamedConstructors(): void
    {
        self::assertSame([400, 'x'], [HttpFailure::badRequest('x')->status, HttpFailure::badRequest('x')->detail]);
        self::assertSame(['WWW-Authenticate' => 'Bearer'], HttpFailure::unauthorized('Bearer')->headers);
        self::assertSame(403, HttpFailure::forbidden()->status);
        self::assertSame(404, HttpFailure::notFound()->status);
        self::assertSame(409, HttpFailure::conflict()->status);
    }

    public function testHttpFailureRejectsNonErrorStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Reflection bypasses the static int<400, 599> contract, as untyped callers would.
        new \ReflectionClass(HttpFailure::class)->newInstance(302);
    }

    public function testGlobalMiddlewareSeesRenderedRouteErrors(): void
    {
        $app = new AppBuilder();
        $app->middleware(self::headerMiddleware('X-Global', 'yes'));
        $app->get('/', static fn (): Text => throw new LogicException('boom'));
        $client = new TestClient($app->build());

        self::assertSame('yes', $client->get('/')->getHeaderLine('X-Global'));
        self::assertSame('yes', $client->get('/missing')->getHeaderLine('X-Global'));
    }

    public function testFailureInGlobalMiddlewareIsRenderedByTheOuterBoundary(): void
    {
        $observer = new RecordingObserver();
        $app = new AppBuilder();
        $app->observe($observer);
        $app->middleware(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new HttpFailure(429, 'Slow down.');
            }
        });
        $app->get('/', static fn (): Text => new Text('ok'));

        $response = new TestClient($app->build())->get('/');

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('Slow down.', self::problem($response)['detail'] ?? null);
        self::assertSame([
            'begin GET HTTP/1.1',
            'failed Shirakaba\Core\Http\HttpFailure: Slow down.',
            'responseReady 429',
        ], $observer->events);
    }

    public function testObserverSeesEventsInOrder(): void
    {
        $observer = new RecordingObserver();
        $app = new AppBuilder();
        $app->observe($observer);
        $app->get('/users/{id}', static fn (#[Path] int $id): Text => new Text('user'))->name('users.show');
        $app->get('/boom', static fn (): Text => throw new LogicException('boom'));
        $client = new TestClient($app->build());

        $client->get('/users/1');
        $client->get('/users/x');
        $client->get('/boom');
        $client->get('/missing');
        $client->head('/users/1');

        self::assertSame([
            'begin GET HTTP/1.1',
            'matched GET /users/{id} users.show',
            'handlerFinished',
            'responseReady 200',
            'begin GET HTTP/1.1',
            'matched GET /users/{id} users.show',
            'failed Shirakaba\Core\Extraction\ExtractionFailure: Invalid path parameter "id": expected an integer.',
            'responseReady 400',
            'begin GET HTTP/1.1',
            'matched GET /boom -',
            'failed LogicException: boom',
            'responseReady 500',
            'begin GET HTTP/1.1',
            'responseReady 404',
            'begin HEAD HTTP/1.1',
            'matched GET /users/{id} users.show',
            'handlerFinished',
            'responseReady 200',
        ], $observer->events);
    }

    public function testSeveralObserversAllReceiveEvents(): void
    {
        $first = new RecordingObserver();
        $broken = new RecordingObserver(explode: true);
        $second = new RecordingObserver();
        $app = new AppBuilder();
        $app->observe($first, $broken)->observe($second);
        $app->get('/', static fn (): Text => new Text('ok'));

        new TestClient($app->build())->get('/');

        $expected = ['begin GET HTTP/1.1', 'matched GET / -', 'handlerFinished', 'responseReady 200'];
        self::assertSame($expected, $first->events);
        self::assertSame($expected, $second->events, 'A failing observer does not stop the others.');
    }

    public function testObserverExceptionsNeverChangeTheResponse(): void
    {
        $observer = new RecordingObserver(explode: true);
        $app = new AppBuilder();
        $app->observe($observer);
        $app->get('/', static fn (): Text => new Text('ok'));

        $response = new TestClient($app->build())->get('/');

        self::assertSame('ok', (string) $response->getBody());
        self::assertContains('responseReady 200', $observer->events);
    }

    private static function headerMiddleware(string $name, string $value): MiddlewareInterface
    {
        return new readonly class ($name, $value) implements MiddlewareInterface {
            public function __construct(private string $name, private string $value) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader($this->name, $this->value);
            }
        };
    }
}
