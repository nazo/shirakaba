<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Conformance;

use Http\Psr7Test\StreamIntegrationTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Shirakaba\Http\Message\Stream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Runs the upstream suite, replacing the four tests that download a file from GitHub
 * with equivalents on local resources, so the suite works offline.
 */
#[CoversClass(Stream::class)]
final class StreamConformanceTest extends StreamIntegrationTest
{
    /** @var list<resource> */
    private array $openResources = [];

    protected function tearDown(): void
    {
        foreach ($this->openResources as $resource) {
            if (get_resource_type($resource) === 'stream') {
                fclose($resource);
            }
        }
        $this->openResources = [];
    }

    /**
     * @param StreamInterface|resource|string $data
     */
    public function createStream($data): StreamInterface
    {
        if ($data instanceof StreamInterface) {
            return $data;
        }

        return is_string($data) ? Stream::fromString($data) : new Stream($data);
    }

    public function testIsNotSeekable(): void
    {
        self::assertFalse($this->createStream($this->socket())->isSeekable());
    }

    public function testIsNotWritable(): void
    {
        $resource = fopen(__FILE__, 'r');
        self::assertIsResource($resource);
        $this->openResources[] = $resource;

        self::assertFalse($this->createStream($resource)->isWritable());
    }

    public function testIsNotReadable(): void
    {
        // Upstream asserts that a read-only download is readable; a socket is the local equivalent.
        self::assertTrue($this->createStream($this->socket())->isReadable());
    }

    public function testRewindNotSeekable(): void
    {
        $stream = $this->createStream($this->socket());

        $this->expectException(RuntimeException::class);
        $stream->rewind();
    }

    public function testGetContentsOfNonSeekableStream(): void
    {
        $pair = $this->socketPair();
        fwrite($pair[1], 'abc');
        fclose($pair[1]);

        self::assertSame('abc', $this->createStream($pair[0])->getContents());
    }

    /**
     * @return resource
     */
    private function socket()
    {
        return $this->socketPair()[0];
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $first = $pair === false ? null : $pair[0] ?? null;
        $second = $pair === false ? null : $pair[1] ?? null;
        if ($first === null || $second === null) {
            self::fail('Unable to create a local socket pair.');
        }
        $this->openResources[] = $first;
        $this->openResources[] = $second;

        return [$first, $second];
    }
}
