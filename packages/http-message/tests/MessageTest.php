<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Shirakaba\Http\Message\Message;
use Shirakaba\Http\Message\Response;
use Shirakaba\Http\Message\Stream;

#[CoversClass(Message::class)]
#[CoversClass(Response::class)]
final class MessageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|non-empty-list<string>}>
     */
    public static function injectedHeaders(): iterable
    {
        yield 'CRLF in value' => ['X-Test', "value\r\nSet-Cookie: a=b"];
        yield 'LF in value' => ['X-Test', "value\nX-Other: 1"];
        yield 'NUL in value' => ['X-Test', "value\0"];
        yield 'CRLF in list item' => ['X-Test', ['ok', "bad\r\n"]];
        yield 'CRLF in name' => ["X-Test\r\nX-Other", 'value'];
        yield 'space in name' => ['X Test', 'value'];
        yield 'colon in name' => ['X-Test:', 'value'];
        yield 'empty name' => ['', 'value'];
    }

    /**
     * Values the static types already forbid; the runtime check is the last line of defence
     * for callers that only see PSR-7's loosely typed signature.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function mistypedHeaderValues(): iterable
    {
        yield 'empty list' => [[]];
        yield 'object in list' => [[new \stdClass()]];
        yield 'float' => [1.5];
        yield 'null' => [null];
    }

    #[DataProvider('mistypedHeaderValues')]
    public function testRejectsMistypedHeaderValues(mixed $value): void
    {
        $response = new Response();

        $this->expectException(InvalidArgumentException::class);
        new ReflectionMethod($response, 'withHeader')->invoke($response, 'X-Test', $value);
    }

    /**
     * @param string|non-empty-list<string> $value
     */
    #[DataProvider('injectedHeaders')]
    public function testRejectsInvalidHeaders(string $name, string|array $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new Response()->withHeader($name, $value);
    }

    /**
     * @param string|non-empty-list<string> $value
     */
    #[DataProvider('injectedHeaders')]
    public function testConstructorRejectsInvalidHeaders(string $name, string|array $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response(headers: [$name => $value]);
    }

    public function testConstructorMergesHeadersCaseInsensitively(): void
    {
        $response = new Response(headers: ['X-Foo' => 'a', 'x-foo' => ['b', 'c']]);

        self::assertSame(['X-Foo' => ['a', 'b', 'c']], $response->getHeaders());
        self::assertSame('a, b, c', $response->getHeaderLine('X-FOO'));
    }

    public function testTrimsSurroundingWhitespaceAndCastsIntegers(): void
    {
        $response = new Response()
            ->withHeader('X-Foo', " \tvalue\t ")
            ->withHeader('Content-Length', 42);

        self::assertSame(['value'], $response->getHeader('x-foo'));
        self::assertSame(['42'], $response->getHeader('content-length'));
    }

    public function testNumericHeaderNamesSurviveUpdates(): void
    {
        $response = new Response(headers: ['123' => 'a'])
            ->withHeader('X-Other', 'b')
            ->withAddedHeader('123', 'c')
            ->withHeader('456', 'd');

        self::assertSame(['a', 'c'], $response->getHeader('123'));
        self::assertSame(['d'], $response->getHeader('456'));
        self::assertSame(['b'], $response->getHeader('X-Other'));
    }

    public function testWithHeaderReplacesValueAndName(): void
    {
        $response = new Response(headers: ['x-foo' => 'a'])->withHeader('X-Foo', 'b');

        self::assertSame(['X-Foo' => ['b']], $response->getHeaders());
    }

    public function testWithoutMissingHeaderReturnsSameInstance(): void
    {
        $response = new Response();

        self::assertSame($response, $response->withoutHeader('X-Missing'));
    }

    public function testRejectsInvalidProtocolVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new Response()->withProtocolVersion('HTTP/1.1');
    }

    public function testBodyIsCreatedLazilyAndReused(): void
    {
        $response = new Response();
        $body = $response->getBody();

        self::assertSame($body, $response->getBody());
        self::assertSame('', (string) $body);
    }

    public function testStringBodyIsReadable(): void
    {
        self::assertSame('hello', (string) new Response(body: 'hello')->getBody());
    }

    public function testWithSameBodyReturnsSameInstance(): void
    {
        $body = Stream::fromString('x');
        $response = new Response(body: $body);

        self::assertSame($response, $response->withBody($body));
    }
}
