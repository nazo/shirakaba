<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Http\Message\Request;
use Shirakaba\Http\Message\RequestBase;
use Shirakaba\Http\Message\Uri;

#[CoversClass(Request::class)]
#[CoversClass(RequestBase::class)]
final class RequestTest extends TestCase
{
    public function testHostHeaderIsDerivedFromUriAndPlacedFirst(): void
    {
        $request = new Request('GET', 'http://example.com:8080/path', ['Accept' => 'text/plain']);

        self::assertSame(['Host', 'Accept'], array_keys($request->getHeaders()));
        self::assertSame('example.com:8080', $request->getHeaderLine('Host'));
    }

    public function testExplicitHostHeaderIsKept(): void
    {
        $request = new Request('GET', 'http://example.com/', ['host' => 'other.example']);

        self::assertSame('other.example', $request->getHeaderLine('Host'));
    }

    public function testWithUriUpdatesHostUnlessPreserved(): void
    {
        $request = new Request('GET', 'http://a.example/');
        $uri = new Uri('http://b.example/');

        self::assertSame('b.example', $request->withUri($uri)->getHeaderLine('Host'));
        self::assertSame('a.example', $request->withUri($uri, preserveHost: true)->getHeaderLine('Host'));
        self::assertSame(
            'b.example',
            new Request('GET', '/')
                ->withUri($uri, preserveHost: true)
                ->getHeaderLine('Host'),
        );
    }

    public function testWithUriWithoutHostKeepsHostHeader(): void
    {
        $request = new Request('GET', 'http://a.example/')->withUri(new Uri('/path'));

        self::assertSame('a.example', $request->getHeaderLine('Host'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requestTargets(): iterable
    {
        yield 'empty path' => ['http://example.com', '/'];
        yield 'query' => ['http://example.com/a?b=c', '/a?b=c'];
        yield 'leading slashes' => ['http://example.com//evil.example', '/evil.example'];
    }

    #[DataProvider('requestTargets')]
    public function testDerivesOriginFormRequestTarget(string $uri, string $expected): void
    {
        self::assertSame($expected, new Request('GET', $uri)->getRequestTarget());
    }

    public function testRootlessPathGetsLeadingSlashInRequestTarget(): void
    {
        self::assertSame('/path', new Request('GET', new Uri()->withPath('path'))->getRequestTarget());
    }

    public function testAcceptsAsteriskAndAuthorityFormTargets(): void
    {
        $request = new Request('OPTIONS', '/');

        self::assertSame('*', $request->withRequestTarget('*')->getRequestTarget());
        self::assertSame('example.com:443', $request->withRequestTarget('example.com:443')->getRequestTarget());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRequestTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['/a b'];
        yield 'tab' => ["/a\tb"];
        yield 'CRLF' => ["/a\r\nHost: evil"];
        yield 'NUL' => ["/a\0b"];
        yield 'other C0' => ["/a\x1Fb"];
        yield 'DEL' => ["/a\x7Fb"];
    }

    #[DataProvider('invalidRequestTargets')]
    public function testRejectsInvalidRequestTargets(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new Request('GET', '/')->withRequestTarget($target);
    }

    public function testAcceptsAbsoluteFormTarget(): void
    {
        self::assertSame(
            'http://example.com/a?b',
            new Request('GET', '/')
                ->withRequestTarget('http://example.com/a?b')
                ->getRequestTarget(),
        );
    }

    public function testMethodCaseIsPreserved(): void
    {
        self::assertSame('get', new Request('get', '/')->getMethod());
        self::assertSame(
            'PURGE',
            new Request('GET', '/')
                ->withMethod('PURGE')
                ->getMethod(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['GET /'];
        yield 'CRLF' => ["GET\r\n"];
    }

    #[DataProvider('invalidMethods')]
    public function testRejectsInvalidMethods(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request($method, '/');
    }
}
