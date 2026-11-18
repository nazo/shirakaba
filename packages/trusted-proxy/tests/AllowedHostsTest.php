<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\TrustedProxy\AllowedHosts;
use Shirakaba\TrustedProxy\ForwardedHeader;
use Shirakaba\TrustedProxy\TrustedProxies;

#[CoversClass(AllowedHosts::class)]
final class AllowedHostsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'exact' => ['app.example.com', true];
        yield 'case-insensitive' => ['APP.Example.com', true];
        yield 'trailing dot' => ['app.example.com.', true];
        yield 'subdomain by wildcard' => ['eu.api.example.org', true];
        yield 'wildcard excludes the apex' => ['example.org', false];
        yield 'lookalike suffix' => ['evilexample.org', false];
        yield 'other host' => ['evil.example', false];
        yield 'ipv6' => ['[::1]', true];
        yield 'empty' => ['', false];
    }

    #[DataProvider('hosts')]
    public function testMatchesHosts(string $host, bool $expected): void
    {
        self::assertSame($expected, new AllowedHosts(['app.example.com', '*.example.org', '[::1]'])->allows($host));
    }

    public function testRejectsUnknownHostsWithProblemDetails(): void
    {
        $app = new AppBuilder();
        $app->middleware(new AllowedHosts(['app.example.com']));
        $app->get('/', static fn (): Text => new Text('ok'));
        $application = $app->build();

        self::assertSame('ok', (string) new TestClient($application, baseUri: 'http://app.example.com:8080')->get('/')->getBody());
        $rejected = new TestClient($application, baseUri: 'http://evil.example')->get('/');
        self::assertSame(400, $rejected->getStatusCode());
        self::assertSame('application/problem+json', $rejected->getHeaderLine('Content-Type'));
    }

    public function testChecksTheHostSetByTrustedProxies(): void
    {
        $app = new AppBuilder();
        $app->middleware(
            new TrustedProxies(['10.0.0.0/8'], [ForwardedHeader::Host]),
            new AllowedHosts(['app.example.com']),
        );
        $app->get('/', static fn (): Text => new Text('ok'));
        $client = new TestClient($app->build(), baseUri: 'http://internal.local');
        $server = ['REMOTE_ADDR' => '10.0.0.2'];

        self::assertSame(200, $client->request('GET', '/', ['X-Forwarded-Host' => 'app.example.com'], serverParams: $server)->getStatusCode());
        self::assertSame(400, $client->request('GET', '/', ['X-Forwarded-Host' => 'evil.example'], serverParams: $server)->getStatusCode());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty' => [[]];
        yield 'with port' => [['app.example.com:443']];
        yield 'inner wildcard' => [['app.*.com']];
        yield 'bare wildcard' => [['*']];
    }

    /**
     * @param list<string> $hosts
     */
    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfigurations(array $hosts): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AllowedHosts($hosts);
    }
}
