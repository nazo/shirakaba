<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Http\Message\Response;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\TrustedProxy\ClientAddress;
use Shirakaba\TrustedProxy\ForwardedHeader;
use Shirakaba\TrustedProxy\IpRange;
use Shirakaba\TrustedProxy\TrustedProxies;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(TrustedProxies::class)]
#[CoversClass(IpRange::class)]
#[CoversClass(ClientAddress::class)]
#[CoversClass(ForwardedHeader::class)]
final class TrustedProxiesTest extends TestCase
{
    private const array X_FORWARDED = [ForwardedHeader::For, ForwardedHeader::Proto, ForwardedHeader::Host, ForwardedHeader::Port];

    /**
     * @param array<string, string> $headers
     * @param non-empty-list<ForwardedHeader> $trusted
     * @return array{string, string|null}
     */
    private static function through(string $remote, array $headers, array $trusted = self::X_FORWARDED): array
    {
        $request = new ServerRequest('GET', 'http://internal.local:8080/path?q=1', $headers, serverParams: ['REMOTE_ADDR' => $remote]);
        $seen = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response();
            }
        };

        new TrustedProxies(['10.0.0.0/8', '2001:db8::/32'], $trusted)->process($request, $seen);
        self::assertNotNull($seen->request);

        return [(string) $seen->request->getUri(), ClientAddress::of($seen->request)];
    }

    public function testUntrustedPeerChangesNothing(): void
    {
        self::assertSame(
            ['http://internal.local:8080/path?q=1', '203.0.113.9'],
            self::through('203.0.113.9', ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example']),
        );
    }

    public function testXForwardedFromTrustedProxy(): void
    {
        self::assertSame(
            ['https://api.example.com/path?q=1', '198.51.100.7'],
            self::through('10.0.0.2', ['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'api.example.com']),
        );
    }

    public function testSpoofedLeadingEntriesAreIgnored(): void
    {
        // The client claimed to be 1.1.1.1; the trusted proxy appended the real address.
        self::assertSame('198.51.100.7', self::through('10.0.0.2', ['X-Forwarded-For' => '1.1.1.1, 198.51.100.7, 10.0.0.5'])[1]);
    }

    public function testProtoUsesTheValueOfTheNearestProxy(): void
    {
        self::assertSame('https://internal.local/path?q=1', self::through('10.0.0.2', ['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'http, https'])[0]);
    }

    public function testForwardedHeader(): void
    {
        self::assertSame(
            ['https://api.example.com/path?q=1', '2001:db9::1'],
            self::through('2001:db8::10', ['Forwarded' => 'for="[2001:db9::1]:4711";proto=https;host=api.example.com, for=10.0.0.3', 'X-Forwarded-For' => '9.9.9.9'], [ForwardedHeader::Forwarded]),
        );
    }

    public function testForwardedWithUnknownClientStopsAtTheLastKnownHop(): void
    {
        self::assertSame('10.0.0.3', self::through('10.0.0.2', ['Forwarded' => 'for=unknown, for=10.0.0.3'], [ForwardedHeader::Forwarded])[1]);
    }

    public function testHostWithPort(): void
    {
        self::assertSame('https://api.example.com:8443/path?q=1', self::through('10.0.0.2', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'api.example.com', 'X-Forwarded-Port' => '8443'])[0]);
    }

    public function testMalformedValuesAreIgnored(): void
    {
        self::assertSame(
            ['http://internal.local:8080/path?q=1', '10.0.0.2'],
            self::through('10.0.0.2', ['X-Forwarded-For' => 'not-an-ip', 'X-Forwarded-Proto' => 'gopher', 'X-Forwarded-Host' => 'evil.example/path']),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'ipv4 in network' => ['10.0.0.0/8', '10.255.1.2', true];
        yield 'ipv4 outside' => ['10.0.0.0/8', '11.0.0.1', false];
        yield 'odd prefix' => ['192.168.0.0/23', '192.168.1.200', true];
        yield 'odd prefix outside' => ['192.168.0.0/23', '192.168.2.1', false];
        yield 'single address' => ['127.0.0.1', '127.0.0.1', true];
        yield 'ipv6' => ['2001:db8::/32', '2001:db8:1::1', true];
        yield 'family mismatch' => ['10.0.0.0/8', '::ffff:10.0.0.1', false];
        yield 'not an address' => ['10.0.0.0/8', 'example.com', false];
    }

    #[DataProvider('ranges')]
    public function testIpRanges(string $range, string $address, bool $expected): void
    {
        self::assertSame($expected, IpRange::parse($range)->contains($address));
    }

    public function testUntrustedHeadersAreIgnored(): void
    {
        // The proxy sets X-Forwarded-For and -Proto; the client sent the rest.
        self::assertSame(
            ['https://internal.local/path?q=1', '198.51.100.7'],
            self::through('10.0.0.2', [
                'X-Forwarded-For' => '198.51.100.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'evil.example',
                'Forwarded' => 'for=1.1.1.1;host=evil.example',
            ], [ForwardedHeader::For, ForwardedHeader::Proto]),
        );
    }

    public function testForwardedIsIgnoredUnlessTrusted(): void
    {
        self::assertSame(
            ['http://internal.local:8080/path?q=1', '198.51.100.7'],
            self::through('10.0.0.2', ['Forwarded' => 'for=1.1.1.1;proto=https;host=evil.example', 'X-Forwarded-For' => '198.51.100.7'], [ForwardedHeader::For]),
        );
    }

    public function testXForwardedIsIgnoredWhenForwardedIsTrusted(): void
    {
        self::assertSame('10.0.0.2', self::through('10.0.0.2', ['X-Forwarded-For' => '1.1.1.1'], [ForwardedHeader::Forwarded])[1]);
    }

    public function testRejectsInvalidRanges(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrustedProxies(['10.0.0.0/33'], [ForwardedHeader::For]);
    }

    public function testRequiresNamedHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrustedProxies(['10.0.0.0/8'], []);
    }

    public function testRejectsMixingForwardedWithXForwarded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrustedProxies(['10.0.0.0/8'], [ForwardedHeader::Forwarded, ForwardedHeader::For]);
    }
}
