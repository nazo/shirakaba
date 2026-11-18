<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole\Internal;

use Closure;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response through a Swoole response and closes the body afterwards
 * (design/03-runtimes-and-scopes.md).
 *
 * A body whose end is reached with its first chunk is sent whole; anything longer is sent
 * chunk by chunk as it is produced. Swoole frames the body itself, so Content-Length and
 * Transfer-Encoding from the application are not passed on.
 *
 * @internal
 */
final readonly class Emitter
{
    private const int CHUNK_SIZE = 1 << 16;

    /**
     * @param Closure(): void $onAbort runs when the client went away mid-body
     */
    public function emit(ResponseInterface $response, Connection $connection, Closure $onAbort): void
    {
        $connection->status($response->getStatusCode(), $response->getReasonPhrase());
        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;
            if (strcasecmp($name, 'Content-Length') === 0 || strcasecmp($name, 'Transfer-Encoding') === 0) {
                continue;
            }
            $connection->header($name, array_values($values));
        }

        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $chunk = $body->eof() ? '' : $body->read(self::CHUNK_SIZE);
            if ($chunk === '' || $body->eof()) {
                $connection->end($chunk);

                return;
            }
            do {
                if (!$connection->write($chunk)) {
                    $onAbort();

                    return;
                }
                // Streaming bodies never return "" before their end (design/03-runtimes-and-scopes.md).
                $chunk = $body->read(self::CHUNK_SIZE);
            } while ($chunk !== '');
            $connection->end();
        } finally {
            $body->close();
        }
    }
}
