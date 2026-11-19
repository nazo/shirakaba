<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Internal\RouteParameters;
use Shirakaba\Core\Internal\RouterHandler;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\RouteGroup;
use Shirakaba\Core\Routing\PathTemplate;
use Shirakaba\Core\Routing\Router;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Http\Message\Response;

/**
 * The examples of design/04-routing-and-handlers.md, end to end.
 */
#[CoversClass(Router::class)]
#[CoversClass(PathTemplate::class)]
#[CoversClass(RouterHandler::class)]
#[CoversClass(RouteGroup::class)]
final class RoutingTest extends TestCase
{
    /**
     * @param list<string> $routes "METHOD /template"
     */
    private static function client(array $routes): TestClient
    {
        $app = new AppBuilder();
        foreach ($routes as $route) {
            $space = (int) strpos($route, ' ');
            $app->route([substr($route, 0, $space)], substr($route, $space + 1), static fn (ServerRequestInterface $request): Text => new Text(
                $route . ' ' . json_encode(RouteParameters::of($request), JSON_UNESCAPED_SLASHES),
            ));
        }

        return new TestClient($app->build());
    }

    /**
     * @return iterable<string, array{list<string>, string, string, int, string}>
     */
    public static function examples(): iterable
    {
        yield 'parameter' => [['GET /users/{id}'], 'GET', '/users/42', 200, 'GET /users/{id} {"id":"42"}'];
        yield 'empty parameter' => [['GET /users/{id}'], 'GET', '/users/', 404, ''];
        yield 'encoded slash in parameter' => [['GET /users/{id}'], 'GET', '/users/a%2Fb', 404, ''];
        yield 'decoded once' => [['GET /users/{id}'], 'GET', '/users/a%2520b', 200, 'GET /users/{id} {"id":"a%20b"}'];
        yield 'static beats parameter' => [['GET /users/{id}', 'GET /users/me'], 'GET', '/users/me', 200, 'GET /users/me []'];
        yield 'first segment decides' => [['GET /{y}/b', 'GET /a/{x}'], 'GET', '/a/b', 200, 'GET /a/{x} {"x":"b"}'];
        yield 'catch-all' => [['GET /files/{path...}'], 'GET', '/files/a/b.txt', 200, 'GET /files/{path...} {"path":"a/b.txt"}'];
        yield 'catch-all empty' => [['GET /files/{path...}'], 'GET', '/files/', 200, 'GET /files/{path...} {"path":""}'];
        yield 'catch-all needs a segment' => [['GET /files/{path...}'], 'GET', '/files', 404, ''];
        yield 'static beats catch-all' => [['GET /files/{path...}', 'GET /files/index'], 'GET', '/files/index', 200, 'GET /files/index []'];
        yield 'trailing slash' => [['GET /users'], 'GET', '/users/', 404, ''];
        yield 'root' => [['GET /'], 'GET', '/', 200, 'GET / []'];
        yield 'nul byte' => [['GET /users'], 'GET', '/us%00ers', 400, ''];
        yield 'case-sensitive method' => [['GET /users'], 'get', '/users', 405, ''];
    }

    /**
     * @param list<string> $routes
     */
    #[DataProvider('examples')]
    public function testMatchesAsDocumented(array $routes, string $method, string $target, int $status, string $body): void
    {
        $response = self::client($routes)->request($method, $target);

        self::assertSame($status, $response->getStatusCode());
        if ($status === 200) {
            self::assertSame($body, (string) $response->getBody());
        } else {
            self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testMethodNotAllowedListsAllowedMethods(): void
    {
        $response = self::client(['GET /users', 'POST /users'])->request('DELETE', '/users');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS, POST', $response->getHeaderLine('Allow'));
    }

    public function testHeadFallsBackToGetWithoutBody(): void
    {
        $response = self::client(['GET /users'])->head('/users');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testExplicitHeadRouteWins(): void
    {
        $response = self::client(['GET /users', 'HEAD /users'])->head('/users');

        self::assertSame('HEAD /users []', (string) $response->getBody());
    }

    public function testAutomaticOptions(): void
    {
        $response = self::client(['GET /users'])->options('/users');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Allow'));
    }

    public function testExplicitOptionsRouteWins(): void
    {
        $response = self::client(['GET /users', 'OPTIONS /users'])->options('/users');

        self::assertSame('OPTIONS /users []', (string) $response->getBody());
    }

    public function testSameMethodAndShapeIsABootError(): void
    {
        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage('match the same requests');
        self::client(['GET /users/{id}', 'GET /users/{name}']);
    }

    public function testSameShapeWithDifferentMethodsIsAllowed(): void
    {
        $client = self::client(['GET /users/{id}', 'DELETE /users/{name}']);

        self::assertSame('DELETE /users/{name} {"name":"7"}', (string) $client->delete('/users/7')->getBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTemplates(): iterable
    {
        yield 'no leading slash' => ['users'];
        yield 'catch-all not last' => ['/{path...}/x'];
        yield 'duplicate name' => ['/{id}/{id}'];
        yield 'brace in static text' => ['/a{b}'];
        yield 'invalid name' => ['/{1st}'];
    }

    #[DataProvider('invalidTemplates')]
    public function testRejectsInvalidTemplates(string $template): void
    {
        $this->expectException(InvalidApplication::class);
        self::client(['GET ' . $template]);
    }

    public function testGroupsJoinPrefixesAndAllowTheEmptyPath(): void
    {
        $app = new AppBuilder();
        $app->group('/api', static function (RouteGroup $api): void {
            $api->get('', static fn (): Text => new Text('api root'));
            $api->group('/v1', static function (RouteGroup $v1): void {
                $v1->get('/users/{id}', static fn (#[Path] int $id): Text => new Text('user ' . $id));
            });
        });
        $client = new TestClient($app->build());

        self::assertSame('api root', (string) $client->get('/api')->getBody());
        self::assertSame('user 7', (string) $client->get('/api/v1/users/7')->getBody());
        self::assertSame(404, $client->get('/api/')->getStatusCode());
    }

    public function testRejectsGroupPrefixWithTrailingSlash(): void
    {
        $this->expectException(InvalidApplication::class);
        new AppBuilder()->group('/api/', static function (RouteGroup $api): void {});
    }

    public function testRejectsEmptyPathOutsideGroup(): void
    {
        $app = new AppBuilder();
        $app->get('', static fn (): ResponseInterface => new Response());

        $this->expectException(InvalidApplication::class);
        $app->build();
    }

    public function testDuplicateRouteNamesAreABootError(): void
    {
        $app = new AppBuilder();
        $app->get('/a', static fn (): Text => new Text('a'))->name('same');
        $app->get('/b', static fn (): Text => new Text('b'))->name('same');

        $this->expectException(InvalidApplication::class);
        $app->build();
    }
}
