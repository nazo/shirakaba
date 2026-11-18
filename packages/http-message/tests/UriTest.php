<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Http\Message\Uri;

#[CoversClass(Uri::class)]
final class UriTest extends TestCase
{
    public function testParsesAndNormalisesCase(): void
    {
        $uri = new Uri('HTTPS://User:Pass@EXAMPLE.com:8443/A/b?Q=1#F');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('User:Pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/A/b', $uri->getPath());
        self::assertSame('Q=1', $uri->getQuery());
        self::assertSame('F', $uri->getFragment());
        self::assertSame('User:Pass@example.com:8443', $uri->getAuthority());
        self::assertSame('https://User:Pass@example.com:8443/A/b?Q=1#F', (string) $uri);
    }

    public function testHidesDefaultPorts(): void
    {
        self::assertNull(new Uri('http://example.com:80')->getPort());
        self::assertNull(new Uri('wss://example.com:443')->getPort());
        self::assertSame(443, new Uri('http://example.com:443')->getPort());
        self::assertSame('https://example.com', (string) new Uri('https://example.com:443'));
    }

    public function testKeepsIpv6HostBrackets(): void
    {
        $uri = new Uri('http://[::1]:8080/');

        self::assertSame('[::1]', $uri->getHost());
        self::assertSame('[::1]:8080', $uri->getAuthority());
    }

    public function testEncodesCharactersThatCannotAppearInAUri(): void
    {
        $uri = new Uri('http://example.com/ä b?q=a b#f g');

        self::assertSame('/%C3%A4%20b', $uri->getPath());
        self::assertSame('q=a%20b', $uri->getQuery());
        self::assertSame('f%20g', $uri->getFragment());
    }

    public function testEncodesSquareBracketsOutsideTheHost(): void
    {
        $uri = new Uri('http://[::1]:8080/a[0]?tags[]=x#[f]');

        self::assertSame('[::1]', $uri->getHost());
        self::assertSame('/a%5B0%5D', $uri->getPath());
        self::assertSame('tags%5B%5D=x', $uri->getQuery());
        self::assertSame('%5Bf%5D', $uri->getFragment());
        self::assertSame('tags%5B%5D=x', new Uri('/search?tags[]=x')->getQuery());
        self::assertSame('tags%5B%5D=x', new Uri('?tags[]=x')->getQuery());
    }

    public function testDoesNotEncodeValidPercentEncodingTwice(): void
    {
        self::assertSame('/a%2Fb', new Uri('http://example.com/a%2Fb')->getPath());
        self::assertSame('/a%2Fb%25', new Uri('http://example.com/a%2Fb%')->getPath());
        self::assertSame('/a%2Fb%25', new Uri()->withPath('/a%2Fb%')->getPath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedUris(): iterable
    {
        yield 'unterminated IPv6 literal' => ['http://[::1/'];
        yield 'non-numeric port' => ['http://example.com:port/'];
        yield 'port out of range' => ['http://example.com:65536/'];
        yield 'invalid IPv6 literal' => ['http://[not-an-ip]/'];
    }

    #[DataProvider('malformedUris')]
    public function testRejectsMalformedUris(string $uri): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Uri($uri);
    }

    public function testGetPathCollapsesLeadingSlashesButStringKeepsThem(): void
    {
        $uri = new Uri('http://example.com//evil.example/x');

        self::assertSame('/evil.example/x', $uri->getPath());
        self::assertSame('http://example.com//evil.example/x', (string) $uri);
    }

    public function testStringWithoutAuthorityCannotStartWithTwoSlashes(): void
    {
        self::assertSame('/evil.example', (string) new Uri()->withPath('//evil.example'));
    }

    public function testStringAddsSlashBeforeRootlessPathWhenAuthorityIsPresent(): void
    {
        self::assertSame('http://example.com/path', (string) new Uri('http://example.com')->withPath('path'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHosts(): iterable
    {
        yield 'path' => ['example.com/path'];
        yield 'port' => ['example.com:80'];
        yield 'user info' => ['user@example.com'];
        yield 'whitespace' => ['exa mple.com'];
    }

    #[DataProvider('invalidHosts')]
    public function testWithHostRejectsInvalidHosts(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new Uri()->withHost($host);
    }

    public function testWithPortRejectsOutOfRangePort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new Uri()->withPort(65536);
    }

    public function testComponentSettersEncodeReservedCharacters(): void
    {
        $uri = new Uri('http://example.com')
            ->withPath('/a b?c#d')
            ->withQuery('x=a b#c')
            ->withFragment('f g');

        self::assertSame('http://example.com/a%20b%3Fc%23d?x=a%20b%23c#f%20g', (string) $uri);
    }

    public function testEmptyUserRemovesUserInfoIncludingPassword(): void
    {
        $uri = new Uri('http://user:pass@example.com')->withUserInfo('', 'secret');

        self::assertSame('', $uri->getUserInfo());
        self::assertSame('http://example.com', (string) $uri);
    }

    /**
     * @return iterable<string, array{\Closure(Uri): Uri}>
     */
    public static function schemeLikePaths(): iterable
    {
        yield 'path set without scheme' => [static fn (Uri $uri): Uri => $uri->withPath('a:b')];
        yield 'javascript path' => [static fn (Uri $uri): Uri => $uri->withPath('javascript:alert(1)')];
        yield 'scheme removed' => [static fn (Uri $uri): Uri => new Uri('urn:a:b')->withScheme('')];
    }

    /**
     * @param \Closure(Uri): Uri $build
     */
    #[DataProvider('schemeLikePaths')]
    public function testRejectsRelativePathThatWouldReadAsScheme(\Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build(new Uri());
    }

    public function testAllowsColonOutsideFirstSegmentOrWithScheme(): void
    {
        self::assertSame('./a:b', (string) new Uri()->withPath('./a:b'));
        self::assertSame('a/b:c', (string) new Uri()->withPath('a/b:c'));
        self::assertSame('/a:b', (string) new Uri()->withPath('/a:b'));
        self::assertSame('urn:a:b', (string) new Uri('urn:a:b'));
    }

    /**
     * Components survive a string round trip, except where PSR-7 itself prescribes a
     * different string (a rootless path after an authority, "//" without one).
     *
     * @return iterable<string, array{Uri}>
     */
    public static function roundTripUris(): iterable
    {
        yield 'full' => [new Uri('https://u:p@example.com:8443/a/b?q=1#f')];
        yield 'built' => [new Uri()->withScheme('http')->withHost('example.com')->withPath('/a b')->withQuery('x=?')->withFragment('#')];
        yield 'encoded slash' => [new Uri('http://example.com/a%2Fb')];
        yield 'empty query and fragment' => [new Uri('http://example.com/?#')];
        yield 'relative' => [new Uri()->withPath('a/b')->withQuery('c')];
        yield 'ipv6' => [new Uri('http://[2001:db8::1]:8080/')];
        yield 'ipvfuture' => [new Uri('http://[v1.fe80::a+en1]/')];
        yield 'leading colon segment' => [new Uri()->withPath('./:a')];
    }

    #[DataProvider('roundTripUris')]
    public function testRoundTripKeepsComponents(Uri $uri): void
    {
        $parsed = new Uri((string) $uri);

        self::assertSame(
            [$uri->getScheme(), $uri->getUserInfo(), $uri->getHost(), $uri->getPort(), $uri->getPath(), $uri->getQuery(), $uri->getFragment()],
            [$parsed->getScheme(), $parsed->getUserInfo(), $parsed->getHost(), $parsed->getPort(), $parsed->getPath(), $parsed->getQuery(), $parsed->getFragment()],
        );
    }

    public function testUnchangedComponentReturnsSameInstance(): void
    {
        $uri = new Uri('http://example.com/path?q#f');

        self::assertSame($uri, $uri->withScheme('HTTP'));
        self::assertSame($uri, $uri->withHost('EXAMPLE.COM'));
        self::assertSame($uri, $uri->withPath('/path'));
        self::assertSame($uri, $uri->withQuery('q'));
        self::assertSame($uri, $uri->withFragment('f'));
        self::assertSame($uri, $uri->withPort(null));
        self::assertSame($uri, $uri->withUserInfo(''));
    }
}
