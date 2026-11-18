<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Fixtures;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Read-only, non-seekable stream that returns the scripted chunks in order and reports
 * EOF only after the last one. A RuntimeException in the script is thrown when reached.
 */
final class ScriptedReadStream implements StreamInterface
{
    public bool $closed = false;

    /**
     * @param list<string|RuntimeException> $chunks
     */
    public function __construct(private array $chunks) {}

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->chunks === [];
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = array_shift($this->chunks);
        if ($chunk instanceof RuntimeException) {
            throw $chunk;
        }

        return $chunk ?? '';
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(PHP_INT_MAX);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
