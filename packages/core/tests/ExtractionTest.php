<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\ExtractionFailure;
use Shirakaba\Core\Extraction\Header;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Internal\HandlerInvoker;
use Shirakaba\Core\Internal\ScalarConverter;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Core\Tests\Fixtures\Color;
use Shirakaba\Core\Tests\Fixtures\Priority;
use Shirakaba\Time\LocalDate;
use Shirakaba\Time\Rfc3339;

#[CoversClass(Path::class)]
#[CoversClass(Query::class)]
#[CoversClass(Header::class)]
#[CoversClass(ScalarConverter::class)]
#[CoversClass(HandlerInvoker::class)]
#[CoversClass(ExtractionFailure::class)]
final class ExtractionTest extends TestCase
{
    private static function client(string $path, Closure $handler): TestClient
    {
        $app = new AppBuilder();
        $app->get($path, $handler);

        return new TestClient($app->build());
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array{int, string}
     */
    private static function call(TestClient $client, string $target, array $headers = []): array
    {
        $response = $client->get($target, $headers);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function integers(): iterable
    {
        yield 'zero' => ['0', 200, 'int(0)'];
        yield 'negative' => ['-12', 200, 'int(-12)'];
        yield 'max' => [(string) PHP_INT_MAX, 200, 'int(' . PHP_INT_MAX . ')'];
        yield 'min' => [(string) PHP_INT_MIN, 200, 'int(' . PHP_INT_MIN . ')'];
        yield 'overflow' => ['9223372036854775808', 400, ''];
        yield 'leading plus' => ['%2B1', 400, ''];
        yield 'leading zero' => ['01', 400, ''];
        yield 'negative zero' => ['-0', 400, ''];
        yield 'exponent' => ['1e3', 400, ''];
        yield 'whitespace' => ['%201', 400, ''];
        yield 'decimal' => ['1.0', 400, ''];
    }

    #[DataProvider('integers')]
    public function testPathIntegersAreStrict(string $value, int $status, string $body): void
    {
        $client = self::client('/n/{n}', static fn (#[Path] int $n): Text => new Text(get_debug_type($n) . '(' . $n . ')'));

        [$actualStatus, $actualBody] = self::call($client, '/n/' . $value);

        self::assertSame($status, $actualStatus);
        if ($status === 200) {
            self::assertSame($body, $actualBody);
        } else {
            self::assertStringContainsString('Invalid path parameter \"n\": expected an integer.', $actualBody);
            self::assertStringNotContainsString($value, $actualBody, 'The received value must not be echoed.');
        }
    }

    public function testPathParameterCanBeRenamed(): void
    {
        $client = self::client('/users/{id}', static fn (#[Path('id')] string $userId): Text => new Text($userId));

        self::assertSame([200, 'a b'], self::call($client, '/users/a%20b'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function queries(): iterable
    {
        yield 'present' => ['/?page=3', 200, 'page=3 tag=none'];
        yield 'default' => ['/', 200, 'page=1 tag=none'];
        yield 'plus is space' => ['/?tag=a+b', 200, 'page=1 tag=a b'];
        yield 'encoded key' => ['/?%70age=2', 200, 'page=2 tag=none'];
        yield 'repeated' => ['/?page=1&page=2', 400, 'must appear at most once'];
        yield 'brackets are not arrays' => ['/?page[]=1', 200, 'page=1 tag=none'];
        yield 'invalid' => ['/?page=two', 400, 'expected an integer'];
        yield 'empty value' => ['/?page=', 400, 'expected an integer'];
        yield 'dots are kept' => ['/?tag.name=x', 200, 'page=1 tag=none'];
    }

    #[DataProvider('queries')]
    public function testQueryParametersComeFromTheRawQueryString(string $target, int $status, string $body): void
    {
        $client = self::client(
            '/',
            static fn (#[Query] int $page = 1, #[Query] string $tag = 'none'): Text => new Text(sprintf('page=%d tag=%s', $page, $tag)),
        );

        [$actualStatus, $actualBody] = self::call($client, $target);

        self::assertSame($status, $actualStatus);
        self::assertStringContainsString($body, $actualBody);
    }

    public function testMissingRequiredQueryIsA400EvenWhenNullable(): void
    {
        $client = self::client('/', static fn (#[Query] ?int $page): Text => new Text(var_export($page, true)));

        [$status, $body] = self::call($client, '/');

        self::assertSame(400, $status);
        self::assertStringContainsString('Missing query parameter \"page\".', $body);
    }

    public function testNullDefaultMakesQueryOptional(): void
    {
        $client = self::client('/', static fn (#[Query] ?int $page = null): Text => new Text(var_export($page, true)));

        self::assertSame([200, 'NULL'], self::call($client, '/'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function booleans(): iterable
    {
        yield 'true' => ['true', 200, 'true'];
        yield 'one' => ['1', 200, 'true'];
        yield 'false' => ['false', 200, 'false'];
        yield 'zero' => ['0', 200, 'false'];
        yield 'yes' => ['yes', 400, ''];
        yield 'uppercase' => ['TRUE', 400, ''];
    }

    #[DataProvider('booleans')]
    public function testBooleans(string $value, int $status, string $body): void
    {
        $client = self::client('/', static fn (#[Query] bool $flag): Text => new Text(var_export($flag, true)));

        [$actualStatus, $actualBody] = self::call($client, '/?flag=' . $value);

        self::assertSame($status, $actualStatus);
        if ($status === 200) {
            self::assertSame($body, $actualBody);
        }
    }

    public function testBackedEnums(): void
    {
        $client = self::client(
            '/{color}',
            static fn (#[Path] Color $color, #[Query] Priority $priority = Priority::Normal): Text => new Text($color->name . ' ' . $priority->name),
        );

        self::assertSame([200, 'Red Normal'], self::call($client, '/red'));
        self::assertSame([200, 'Blue High'], self::call($client, '/blue?priority=2'));
        [$status, $body] = self::call($client, '/green');
        self::assertSame(400, $status);
        self::assertStringContainsString('expected one of: red, blue', $body);
        self::assertSame(400, self::call($client, '/red?priority=02')[0]);
    }

    public function testDatesAndInstants(): void
    {
        $client = self::client('/{day}', static fn (#[Path] LocalDate $day, #[Query] ?DateTimeImmutable $since = null): Text => new Text(
            $day . ' ' . ($since === null ? '-' : Rfc3339::format($since)),
        ));

        self::assertSame([200, '2026-11-11 -'], self::call($client, '/2026-11-11'));
        self::assertSame([200, '2026-11-11 2026-11-11T00:30:00.000000Z'], self::call($client, '/2026-11-11?since=2026-11-11T09:30:00%2B09:00'));
        [$status, $body] = self::call($client, '/2026-11-11?since=2026-11-11T09:30:00');
        self::assertSame(400, $status, 'An instant without an offset is refused.');
        self::assertStringContainsString('RFC 3339', $body);
        self::assertSame(400, self::call($client, '/2026-02-30')[0]);
    }

    public function testRejectsMutableDateTime(): void
    {
        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage('needs DateTimeImmutable');
        self::client('/', static fn (#[Query] \DateTime $at): Text => new Text(''))->get('/');
    }

    public function testHeaders(): void
    {
        $client = self::client('/', static fn (#[Header('X-Count')] int $count = 0): Text => new Text((string) $count));

        self::assertSame([200, '5'], self::call($client, '/', ['x-count' => '5']));
        self::assertSame([200, '0'], self::call($client, '/'));
        self::assertSame(400, self::call($client, '/', ['X-Count' => ['1', '2']])[0]);
        self::assertSame(400, self::call($client, '/', ['X-Count' => 'many'])[0]);
    }

    public function testCookies(): void
    {
        $client = self::client(
            '/',
            static fn (
                #[\Shirakaba\Core\Extraction\Cookie('theme')] string $theme = 'light',
                #[\Shirakaba\Core\Extraction\Cookie('visits')] int $visits = 0,
            ): Text => new Text($theme . ' ' . $visits),
        );

        self::assertSame([200, 'dark 3'], self::call($client, '/', ['Cookie' => 'theme=dark; visits=3']));
        self::assertSame([200, 'light 0'], self::call($client, '/'));
        self::assertSame(400, self::call($client, '/', ['Cookie' => 'visits=many'])[0]);
    }

    public function testBodyParameterMayComeFirstAndIsResolvedLast(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[Fixtures\JsonBody] string $body, #[Header('X-Count')] int $count): Text => new Text($body . ' ' . $count));
        $client = new TestClient($app->build());

        $response = $client->post('/', 'hello', ['X-Count' => '2']);
        self::assertSame([200, 'hello 2'], [$response->getStatusCode(), (string) $response->getBody()]);
        self::assertSame(400, $client->post('/', 'hello')->getStatusCode());
    }

    public function testInjectsRequestAndScope(): void
    {
        $client = self::client('/', static fn (ServerRequestInterface $request, RequestScope $scope): Text => new Text(
            $request->getMethod() . ' ' . ($scope === RequestScope::of($request) ? 'same scope' : 'other scope'),
        ));

        self::assertSame([200, 'GET same scope'], self::call($client, '/'));
    }

    public function testInvokableObjectsAndFirstClassCallablesAreHandlers(): void
    {
        $handler = new class {
            public function __invoke(#[Path] int $id): ResponseInterface
            {
                return new \Shirakaba\Http\Message\Response(200, body: 'invokable ' . $id);
            }

            public function show(#[Path] int $id): Text
            {
                return new Text('method ' . $id);
            }
        };
        $app = new AppBuilder();
        $app->get('/a/{id}', $handler);
        $app->get('/b/{id}', $handler->show(...));
        $client = new TestClient($app->build());

        self::assertSame('invokable 1', (string) $client->get('/a/1')->getBody());
        self::assertSame('method 2', (string) $client->get('/b/2')->getBody());
    }
}
