<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Http\Message\Stream;
use RuntimeException;

#[CoversClass(Stream::class)]
final class StreamTest extends TestCase
{
    public function testFromStringIsReadableWritableAndSeekable(): void
    {
        $stream = Stream::fromString('hello');

        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isSeekable());
        self::assertSame(0, $stream->tell());
        self::assertSame('hello', $stream->getContents());
    }

    public function testSizeIsRecalculatedAfterWrite(): void
    {
        $stream = Stream::fromString('abc');
        self::assertSame(3, $stream->getSize());

        $stream->seek(0, SEEK_END);
        $stream->write('def');

        self::assertSame(6, $stream->getSize());
    }

    public function testFromFileRejectsInvalidMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Stream::fromFile('php://memory', 'z');
    }

    public function testFromFileThrowsInsteadOfWarning(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Unable to open ".*missing\.txt" with mode "r"\.$/');
        Stream::fromFile(sys_get_temp_dir() . '/shirakaba-' . bin2hex(random_bytes(6)) . '/missing.txt');
    }

    public function testRejectsNonStreamResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Stream(stream_context_create());
    }

    public function testDetachedStreamFailsLoudly(): void
    {
        $stream = Stream::fromString('abc');
        $resource = $stream->detach();

        self::assertIsResource($resource);
        self::assertNull($stream->detach());
        self::assertNull($stream->getSize());
        self::assertTrue($stream->eof());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('uri'));
        self::assertSame('', (string) $stream);

        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    public function testResourceClosedByOwnerBehavesLikeDetachedStream(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $stream = new Stream($resource);
        fclose($resource);

        self::assertNull($stream->getSize());
        self::assertTrue($stream->eof());
        self::assertSame([], $stream->getMetadata());
        self::assertSame('', (string) $stream);

        $this->expectExceptionMessage('closed by its owner');
        $stream->read(1);
    }

    public function testSizeReflectsWritesThroughOtherHandles(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $stream = new Stream($resource);
        self::assertSame(0, $stream->getSize());

        fwrite($resource, 'abc');

        self::assertSame(3, $stream->getSize());
    }

    public function testRejectsNegativeReadLength(): void
    {
        $this->expectException(RuntimeException::class);
        Stream::fromString('abc')->read(-1);
    }

    public function testReadOfZeroBytesReturnsEmptyString(): void
    {
        self::assertSame('', Stream::fromString('abc')->read(0));
    }

    public function testCloseReleasesResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        $stream->close();

        self::assertSame('Unknown', get_resource_type($resource), 'A closed resource reports the type "Unknown".');
    }

    public function testDestructionDoesNotCloseCallerOwnedResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);

        $stream = new Stream($resource);
        unset($stream);

        self::assertSame('stream', get_resource_type($resource));
    }
}
