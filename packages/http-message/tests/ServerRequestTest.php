<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\UploadedFile;

#[CoversClass(ServerRequest::class)]
final class ServerRequestTest extends TestCase
{
    public function testAcceptsNestedUploadedFiles(): void
    {
        $file = new UploadedFile(Stream::fromString('x'), 1);
        $request = new ServerRequest('POST', '/', uploadedFiles: ['docs' => [['file' => $file]]]);

        self::assertSame(['docs' => [['file' => $file]]], $request->getUploadedFiles());
    }

    public function testRejectsInvalidUploadedFileLeaf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServerRequest('POST', '/', uploadedFiles: ['docs' => ['file' => '/tmp/x']]);
    }

    public function testWithUploadedFilesValidatesTree(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) new ServerRequest('POST', '/')->withUploadedFiles(['file' => 1]);
    }

    public function testRejectsScalarParsedBody(): void
    {
        $request = new ServerRequest('POST', '/');

        $this->expectException(InvalidArgumentException::class);
        new ReflectionMethod($request, 'withParsedBody')->invoke($request, 'a=b');
    }

    public function testAttributesWithNumericNamesSurviveUpdates(): void
    {
        $request = new ServerRequest('GET', '/', attributes: ['10' => 'a'])
            ->withAttribute('20', 'b')
            ->withAttribute('name', null);

        self::assertSame('a', $request->getAttribute('10'));
        self::assertSame('b', $request->getAttribute('20'));
        self::assertNull($request->getAttribute('name', 'default'));
        self::assertSame('default', $request->getAttribute('missing', 'default'));
    }

    public function testWithoutMissingAttributeReturnsSameInstance(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertSame($request, $request->withoutAttribute('missing'));
    }

    public function testDoesNotReadSuperglobals(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertSame([], $request->getServerParams());
        self::assertSame([], $request->getQueryParams());
        self::assertSame([], $request->getCookieParams());
        self::assertNull($request->getParsedBody());
    }
}
