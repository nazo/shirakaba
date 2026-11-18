<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Shirakaba\Http\Message\HttpFactory;

#[CoversClass(HttpFactory::class)]
final class HttpFactoryTest extends TestCase
{
    public function testCreatesMessages(): void
    {
        $factory = new HttpFactory();

        self::assertSame('POST', $factory->createRequest('POST', 'http://example.com/')->getMethod());
        self::assertSame(204, $factory->createResponse(204)->getStatusCode());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '127.0.0.1'])->getServerParams());
        self::assertSame('example.com', $factory->createUri('http://example.com')->getHost());
    }

    public function testUploadedFileSizeDefaultsToStreamSize(): void
    {
        $factory = new HttpFactory();

        self::assertSame(5, $factory->createUploadedFile($factory->createStream('hello'))->getSize());
    }

    public function testRejectsUnsupportedUriType(): void
    {
        $factory = new HttpFactory();

        $this->expectException(InvalidArgumentException::class);
        new ReflectionMethod($factory, 'createRequest')->invoke($factory, 'GET', 42);
    }
}
