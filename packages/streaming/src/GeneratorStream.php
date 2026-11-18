<?php

declare(strict_types=1);

namespace Shirakaba\Streaming;

use Closure;
use Generator;
use Shirakaba\Core\Scope\ScopeOwningBody;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * A read-only, non-seekable body that pulls chunks from an iterable as they are read
 * (design/03-runtimes-and-scopes.md). Empty chunks are skipped, so read() returns "" only at the end.
 *
 * It takes over closing the request scope: the scope is released when the iterable is
 * exhausted, when the stream is closed or detached, or when it is destroyed.
 */
final class GeneratorStream implements StreamInterface, ScopeOwningBody
{
    private ?Generator $chunks;
    private string $buffer = '';
    private int $position = 0;
    private bool $started = false;

    /** @var (Closure(): void)|null */
    private ?Closure $closeScope = null;
    private bool $finished = false;

    /**
     * @param iterable<string> $chunks
     */
    public function __construct(iterable $chunks)
    {
        $this->chunks = (static fn (): Generator => yield from $chunks)();
    }

    public function __destruct()
    {
        $this->finish();
    }

    public function adoptScope(Closure $closeScope): void
    {
        $this->closeScope = $closeScope;
        if ($this->finished) {
            $this->release();
        }
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->finish();
    }

    /**
     * @return null a generated body has no underlying resource
     */
    public function detach()
    {
        $this->finish();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && ($this->chunks === null || ($this->started && !$this->chunks->valid()));
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A streamed body is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('A streamed body is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A streamed body is not writable.');
    }

    public function isReadable(): bool
    {
        return $this->chunks !== null || $this->buffer !== '';
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('The read length must not be negative.');
        }
        while ($this->buffer === '' && $this->pull()) {
            // pull() fills the buffer or ends the stream.
        }
        if ($length === 0 || $this->buffer === '') {
            return '';
        }

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($chunk));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';
        while (($chunk = $this->read(1 << 16)) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * @return bool whether more may follow
     */
    private function pull(): bool
    {
        $chunks = $this->chunks;
        if ($chunks === null) {
            return false;
        }

        try {
            if ($this->started) {
                $chunks->next();
            }
            $this->started = true;
            if (!$chunks->valid()) {
                $this->finish();

                return false;
            }
            $chunk = $chunks->current();
        } catch (Throwable $e) {
            $this->finish();

            throw $e;
        }

        if (!is_string($chunk)) {
            $this->finish();

            throw new RuntimeException(sprintf('A streamed body yielded %s; chunks must be strings.', get_debug_type($chunk)));
        }
        $this->buffer .= $chunk;

        return true;
    }

    private function finish(): void
    {
        $this->chunks = null;
        $this->finished = true;
        $this->release();
    }

    private function release(): void
    {
        $closeScope = $this->closeScope;
        $this->closeScope = null;
        if ($closeScope !== null) {
            $closeScope();
        }
    }
}
