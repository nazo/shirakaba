<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-7 stream over a PHP stream resource.
 *
 * The stream does not close its resource on destruction, because the resource may be
 * owned by the caller. PHP frees an unreferenced resource on its own. If the caller
 * closes a borrowed resource, every later operation fails as if the stream were detached.
 *
 * The size is not cached: the resource can be written through other handles, and a stale
 * size would make an emitter send a wrong Content-Length.
 */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private bool $seekable;
    private bool $readable;
    private bool $writable;
    private ?string $uri;

    /**
     * @param resource $resource
     * @throws InvalidArgumentException when $resource is not an open stream resource
     */
    public function __construct($resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException('A stream must be created from an open stream resource.');
        }

        $this->resource = $resource;
        $metadata = stream_get_meta_data($resource);
        $mode = $metadata['mode'];
        $this->seekable = $metadata['seekable'];
        $this->readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $this->writable = strpbrk($mode, 'waxc+') !== false;
        $this->uri = $metadata['uri'] ?? null;
    }

    /**
     * Creates a seekable, readable and writable in-memory stream holding $content.
     * Content over 2 MiB is moved to a temporary file by PHP.
     */
    public static function fromString(string $content = ''): self
    {
        $stream = new self(self::open('php://temp', 'r+'));
        if ($content !== '') {
            $stream->write($content);
            $stream->rewind();
        }

        return $stream;
    }

    /**
     * @throws RuntimeException when the file cannot be opened
     * @throws InvalidArgumentException when $mode is not a valid fopen() mode
     * @phpstan-impure io (any stream wrapper, not only files)
     */
    public static function fromFile(string $filename, string $mode = 'r'): self
    {
        if (preg_match('/^[rwaxc][bte]*\+?[bte]*$/D', $mode) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid stream mode "%s".', $mode));
        }

        return new self(self::open($filename, $mode));
    }

    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $resource = $this->detach();
        if ($resource !== null && self::isOpen($resource)) {
            fclose($resource);
        }
    }

    /**
     * @return resource|null
     */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->uri = null;
        $this->seekable = false;
        $this->readable = false;
        $this->writable = false;

        return $resource;
    }

    public function getSize(): ?int
    {
        $resource = $this->resource;
        if ($resource === null || !self::isOpen($resource)) {
            return null;
        }

        if ($this->uri !== null) {
            clearstatcache(true, $this->uri);
        }
        $stats = fstat($resource);

        return $stats === false ? null : $stats['size'];
    }

    public function tell(): int
    {
        $position = ftell($this->attached());
        if ($position === false) {
            throw new RuntimeException('Unable to determine the stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        $resource = $this->resource;

        return $resource === null || !self::isOpen($resource) || feof($resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $resource = $this->attached();
        if (!$this->seekable) {
            throw new RuntimeException('The stream is not seekable.');
        }
        if (fseek($resource, $offset, $whence) === -1) {
            throw new RuntimeException(sprintf('Unable to seek to stream position %d with whence %d.', $offset, $whence));
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        $resource = $this->attached();
        if (!$this->writable) {
            throw new RuntimeException('The stream is not writable.');
        }

        $written = fwrite($resource, $string);
        if ($written === false) {
            throw new RuntimeException('Unable to write to the stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        $resource = $this->attached();
        if (!$this->readable) {
            throw new RuntimeException('The stream is not readable.');
        }
        if ($length < 0) {
            throw new RuntimeException('The read length must not be negative.');
        }
        if ($length === 0) {
            return '';
        }

        $data = fread($resource, $length);
        if ($data === false) {
            throw new RuntimeException('Unable to read from the stream.');
        }

        return $data;
    }

    public function getContents(): string
    {
        $resource = $this->attached();
        if (!$this->readable) {
            throw new RuntimeException('The stream is not readable.');
        }

        $contents = stream_get_contents($resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the stream contents.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $resource = $this->resource;
        if ($resource === null || !self::isOpen($resource)) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($resource);

        return $key === null ? $metadata : $metadata[$key] ?? null;
    }

    /**
     * @return resource
     * @throws RuntimeException when the stream has been detached or its resource closed
     */
    private function attached()
    {
        $resource = $this->resource ?? throw new RuntimeException('The stream is detached.');
        if (!self::isOpen($resource)) {
            throw new RuntimeException('The stream resource has been closed by its owner.');
        }

        return $resource;
    }

    /**
     * @param resource $resource
     */
    private static function isOpen($resource): bool
    {
        // A closed resource reports the type "Unknown".
        return get_resource_type($resource) === 'stream';
    }

    /**
     * Opens a stream without reading PHP's last-error state, which is shared by every
     * coroutine in the process and could carry another request's message.
     *
     * @return resource
     * @throws RuntimeException when the file cannot be opened
     */
    private static function open(string $filename, string $mode)
    {
        $resource = @fopen($filename, $mode);

        return $resource === false
            ? throw new RuntimeException(sprintf('Unable to open "%s" with mode "%s".', $filename, $mode))
            : $resource;
    }
}
