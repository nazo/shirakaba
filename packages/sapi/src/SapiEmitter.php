<?php

declare(strict_types=1);

namespace Shirakaba\Sapi;

use Closure;
use LogicException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response through the SAPI: status line, headers, then the body in chunks,
 * and closes the body afterwards (design/03-runtimes-and-scopes.md).
 *
 * The SAPI functions are injected so tests can capture the output.
 */
final readonly class SapiEmitter
{
    private const int CHUNK_SIZE = 1 << 16;

    /** @var Closure(string, bool, int): void */
    private Closure $header;

    /** @var Closure(string): void */
    private Closure $output;

    /** @var Closure(): bool */
    private Closure $headersSent;

    /** @var Closure(): bool */
    private Closure $aborted;

    /**
     * @param (Closure(string, bool, int): void)|null $header defaults to header()
     * @param (Closure(string): void)|null $output defaults to echo with flush()
     * @param (Closure(): bool)|null $headersSent defaults to headers_sent()
     * @param (Closure(): bool)|null $aborted whether the client went away; defaults to connection_aborted()
     */
    public function __construct(?Closure $header = null, ?Closure $output = null, ?Closure $headersSent = null, ?Closure $aborted = null)
    {
        $this->header = $header ?? static function (string $line, bool $replace, int $status): void {
            header($line, $replace, $status);
        };
        $this->output = $output ?? static function (string $chunk): void {
            echo $chunk;
            // Push through PHP's output buffers too, or streamed chunks would wait for the end.
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
        $this->headersSent = $headersSent ?? static fn (): bool => headers_sent();
        $this->aborted = $aborted ?? static fn (): bool => connection_aborted() === 1;
    }

    /**
     * Sends the response. When the client goes away mid-body, sending stops,
     * $onAbort runs (runtimes request the request's Cancellation there) and the body is
     * closed, so a streaming producer stops and the request's cleanup runs. PHP notices a
     * disconnect only when a write fails, so detection happens a chunk or two late; the
     * runtime must call ignore_user_abort(true) for the script to get this far.
     *
     * @param (Closure(): void)|null $onAbort
     * @throws LogicException when output has already started, so headers cannot be sent
     * @phpstan-impure io.output
     */
    public function emit(ResponseInterface $response, ?Closure $onAbort = null): void
    {
        if (($this->headersSent)()) {
            throw new LogicException('Unable to emit the response: headers have already been sent.');
        }

        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        ($this->header)(
            rtrim(sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $status, $reason)),
            true,
            $status,
        );
        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;
            // Set-Cookie must never be folded into one line.
            $first = strcasecmp($name, 'Set-Cookie') !== 0;
            foreach ($values as $value) {
                ($this->header)(sprintf('%s: %s', $name, $value), $first, $status);
                $first = false;
            }
        }

        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    // Streaming bodies never return "" before their end (design/03-runtimes-and-scopes.md).
                    break;
                }
                ($this->output)($chunk);
                if (($this->aborted)()) {
                    if ($onAbort !== null) {
                        $onAbort();
                    }
                    break;
                }
            }
        } finally {
            // Runtime contract of design/03-runtimes-and-scopes.md: release what the body holds, sent or not.
            $body->close();
        }
    }
}
