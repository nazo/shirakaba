<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestCancelled;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Http\Message\HttpFactory;
use Shirakaba\HttpClient\Addresses;
use Shirakaba\HttpClient\HttpClient;
use Shirakaba\HttpClient\HttpClientOptions;
use Shirakaba\HttpClient\HttpRequestSent;
use Shirakaba\HttpClient\NetworkError;
use Shirakaba\HttpClient\RequestRefused;
use Shirakaba\HttpClient\Resolver;
use Psr\Http\Message\RequestInterface;
use Throwable;

#[CoversClass(HttpClient::class)]
#[CoversClass(HttpClientOptions::class)]
#[CoversClass(Addresses::class)]
#[CoversClass(RequestRefused::class)]
#[CoversClass(NetworkError::class)]
#[CoversClass(HttpRequestSent::class)]
final class HttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Fixtures/server.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            null,
        );
        self::assertIsResource($server);
        self::$server = $server;
        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }
        self::fail('The test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /**
     * A resolver for made-up names; everything else resolves as an IP literal or not at all.
     *
     * @param array<string, list<string>> $hosts
     */
    private static function resolver(array $hosts = []): Resolver
    {
        return new class ($hosts) implements Resolver {
            /**
             * @param array<string, list<string>> $hosts
             */
            public function __construct(private array $hosts) {}

            public function resolve(string $host): array
            {
                return $this->hosts[$host] ?? (filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : []);
            }
        };
    }

    /**
     * A client that may reach the loopback test server (and names mapped to it), but nothing
     * else that is not public.
     *
     * @param array<string, list<string>> $hosts
     */
    private static function local(HttpClientOptions $options = new HttpClientOptions(), array $hosts = []): HttpClient
    {
        return new HttpClient(
            new HttpClientOptions(
                $options->timeout,
                $options->connectTimeout,
                $options->maxResponseBytes,
                $options->maxRedirects,
                static fn (string $address): bool => $address === '127.0.0.1' || Addresses::isPublic($address),
                $options->prepare,
            ),
            self::resolver(['other.test' => ['127.0.0.1'], ...$hosts]),
        );
    }

    private static function url(string $path, string $host = '127.0.0.1'): string
    {
        return sprintf('http://%s:%d%s', $host, self::$port, $path);
    }

    private static function request(string $method, string $url, string $body = ''): RequestInterface
    {
        $factory = new HttpFactory();

        return $factory->createRequest($method, $url)->withBody($factory->createStream($body));
    }

    /**
     * @return array{method: string, body: string, uri: string, headers: array<string, string>}
     */
    private static function echoed(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['method'] ?? null);
        self::assertIsString($data['body'] ?? null);
        self::assertIsString($data['uri'] ?? null);
        self::assertIsArray($data['headers'] ?? null);
        $headers = [];
        foreach ($data['headers'] as $name => $value) {
            $headers[(string) $name] = is_string($value) ? $value : '';
        }

        return ['method' => $data['method'], 'body' => $data['body'], 'uri' => $data['uri'], 'headers' => $headers];
    }

    public function testSendsRequestsAndReadsResponses(): void
    {
        $response = self::local()->sendRequest(self::request('POST', self::url('/echo'), 'hello')->withHeader('Content-Type', 'text/plain'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['a', 'b'], $response->getHeader('X-Multi'));
        $echo = self::echoed($response);
        self::assertSame('POST', $echo['method']);
        self::assertSame('hello', $echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertSame('Shirakaba', $echo['headers']['user-agent'] ?? null);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testFollowsRedirectsLikeBrowsers(): void
    {
        $client = self::local();

        $seeOther = self::echoed($client->sendRequest(self::request('POST', self::url('/redirect?status=302&to=/echo'), 'body')));
        self::assertSame(['GET', ''], [$seeOther['method'], $seeOther['body']]);

        $temporary = self::echoed($client->sendRequest(self::request('POST', self::url('/redirect?status=307&to=/echo'), 'body')));
        self::assertSame(['POST', 'body'], [$temporary['method'], $temporary['body']]);

        $relative = self::echoed($client->sendRequest(self::request('GET', self::url('/dir/relative'))));
        self::assertSame('/echo?from=relative', $relative['uri']);
    }

    public function testDropsCredentialsWhenTheOriginChangesAndPreparesEveryHop(): void
    {
        $prepared = [];
        $client = self::local(new HttpClientOptions(prepare: static function (RequestInterface $request) use (&$prepared): RequestInterface {
            $prepared[] = $request->getUri()->getHost();

            return $request->withHeader('Signature', 'for ' . $request->getUri()->getHost());
        }));

        $sameOrigin = self::echoed($client->sendRequest(self::request('GET', self::url('/redirect?to=/echo'))->withHeader('Authorization', 'Bearer t')));
        self::assertIsArray($sameOrigin['headers']);
        self::assertSame('Bearer t', $sameOrigin['headers']['authorization'] ?? null);

        $crossOrigin = self::echoed($client->sendRequest(
            self::request('GET', self::url('/redirect?to=' . rawurlencode(self::url('/echo', 'other.test'))))
                ->withHeader('Authorization', 'Bearer t')
                ->withHeader('Cookie', 'c=1'),
        ));
        self::assertIsArray($crossOrigin['headers']);
        self::assertArrayNotHasKey('authorization', $crossOrigin['headers']);
        self::assertArrayNotHasKey('cookie', $crossOrigin['headers']);
        self::assertSame('for other.test', $crossOrigin['headers']['signature'] ?? null);
        self::assertSame(['127.0.0.1', '127.0.0.1', '127.0.0.1', 'other.test'], $prepared);
    }

    public function testLimitsRedirects(): void
    {
        $loop = self::url('/redirect?to=' . rawurlencode(self::url('/redirect?to=/echo')));
        self::assertSame(302, self::local(new HttpClientOptions(maxRedirects: 0))->sendRequest(self::request('GET', $loop))->getStatusCode());

        $this->expectException(RequestRefused::class);
        $this->expectExceptionMessage('More than 1 redirects.');
        self::local(new HttpClientOptions(maxRedirects: 1))->sendRequest(self::request('GET', $loop));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function largeResponses(): iterable
    {
        yield 'with Content-Length' => ['/big?bytes=5000'];
        yield 'chunked' => ['/chunked'];
    }

    #[DataProvider('largeResponses')]
    public function testAbortsResponsesOverTheLimit(string $path): void
    {
        self::assertSame(900, strlen((string) self::local(new HttpClientOptions(maxResponseBytes: 1000))->sendRequest(self::request('GET', self::url('/big?bytes=900')))->getBody()));

        try {
            self::local(new HttpClientOptions(maxResponseBytes: 1000))->sendRequest(self::request('GET', self::url($path)));
            self::fail('The response is over the limit.');
        } catch (NetworkError $e) {
            self::assertTrue($e->tooLarge);
        }
    }

    public function testTimesOut(): void
    {
        try {
            self::local(new HttpClientOptions(timeout: 0.3))->sendRequest(self::request('GET', self::url('/slow?ms=2000')));
            self::fail('The request should time out.');
        } catch (NetworkError $e) {
            self::assertTrue($e->timedOut);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, list<string>>}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'loopback literal' => ['http://127.0.0.1/', []];
        yield 'IPv6 loopback' => ['http://[::1]/', []];
        yield 'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/', []];
        yield 'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/', []];
        yield 'name resolving to a private address' => ['https://intranet.test/', ['intranet.test' => ['10.0.0.5']]];
        yield 'name mixing public and private addresses' => ['https://rebind.test/', ['rebind.test' => ['93.184.215.14', '192.168.1.1']]];
        yield 'unresolvable name' => ['https://nowhere.test/', []];
        yield 'file scheme' => ['file:///etc/passwd', []];
    }

    /**
     * @param array<string, list<string>> $hosts
     */
    #[DataProvider('refusedTargets')]
    public function testRefusesTargetsThatAreNotPublic(string $url, array $hosts): void
    {
        $this->expectException(RequestRefused::class);
        new HttpClient(resolver: self::resolver($hosts))->sendRequest(self::request('GET', $url));
    }

    public function testChecksEveryRedirectTarget(): void
    {
        $this->expectException(RequestRefused::class);
        $this->expectExceptionMessage('resolves to 10.0.0.5');
        self::local(hosts: ['intranet.test' => ['10.0.0.5']])->sendRequest(self::request('GET', self::url('/redirect?to=' . rawurlencode('http://intranet.test/admin'))));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'public IPv4' => ['93.184.215.14', true];
        yield 'public IPv6' => ['2606:4700:4700::1111', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'private' => ['172.16.0.1', false];
        yield 'shared (CGNAT)' => ['100.64.0.1', false];
        yield 'link-local' => ['169.254.169.254', false];
        yield 'unspecified' => ['0.0.0.0', false];
        yield 'IPv6 loopback' => ['::1', false];
        yield 'IPv6 unique local' => ['fd00::1', false];
        yield 'IPv6 link-local' => ['fe80::1', false];
        yield 'IPv4-mapped private' => ['::ffff:10.0.0.1', false];
        yield 'IPv4-mapped public' => ['::ffff:93.184.215.14', true];
        yield 'NAT64 loopback' => ['64:ff9b::127.0.0.1', false];
        yield 'not an address' => ['localhost', false];
    }

    #[DataProvider('addresses')]
    public function testClassifiesAddresses(string $address, bool $public): void
    {
        self::assertSame($public, Addresses::isPublic($address));
    }

    public function testReportsToTheRequestAndStopsWhenItIsCancelled(): void
    {
        $events = new \ArrayObject();
        $observer = new class ($events) implements Observer {
            /** @param \ArrayObject<int, Event> $events */
            public function __construct(private \ArrayObject $events) {}

            public function begin(RequestSummary $request): Observation
            {
                return new class ($this->events) implements Observation {
                    /** @param \ArrayObject<int, Event> $events */
                    public function __construct(private \ArrayObject $events) {}

                    public function matched(RouteSummary $route): void {}

                    public function handlerFinished(): void {}

                    public function failed(Throwable $error): void {}

                    public function cleanupFailed(Throwable $error): void {}

                    public function recorded(Event $event): void
                    {
                        $this->events->append($event);
                    }

                    public function responseReady(int $status): void {}
                };
            }
        };
        $cancellation = new Cancellation();
        $error = ScopedWork::run([$observer], 'TEST', 'http', $cancellation, static function (RequestScope $scope) use ($cancellation): void {
            $client = self::local()->in($scope);
            $client->sendRequest(self::request('GET', self::url('/redirect?to=/echo')));
            $cancellation->request();
            $client->sendRequest(self::request('GET', self::url('/slow?ms=2000')));
        });

        self::assertInstanceOf(RequestCancelled::class, $error);
        self::assertInstanceOf(\Psr\Http\Client\ClientExceptionInterface::class, $error, 'PSR-18 callers can catch it.');
        $sent = [];
        foreach ($events as $event) {
            if ($event instanceof HttpRequestSent) {
                $sent[] = $event->attributes();
            }
        }
        self::assertSame([
            ['http.request.method' => 'GET', 'url.scheme' => 'http', 'server.address' => '127.0.0.1', 'server.port' => self::$port, 'http.response.status_code' => 302],
            ['http.request.method' => 'GET', 'url.scheme' => 'http', 'server.address' => '127.0.0.1', 'server.port' => self::$port, 'http.response.status_code' => 200],
            ['http.request.method' => 'GET', 'url.scheme' => 'http', 'server.address' => '127.0.0.1', 'server.port' => self::$port, 'error.type' => 'cancelled'],
        ], $sent);
    }
}
