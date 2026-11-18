<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole\Internal;

/**
 * The parts of Swoole\Http\Response the emitter uses, so it can be tested without Swoole.
 *
 * @internal
 */
interface Connection
{
    public function status(int $code, string $reason): void;

    /**
     * @param list<string> $values sent as one header line each
     */
    public function header(string $name, array $values): void;

    /**
     * Sends a chunk of a chunked response.
     *
     * @return bool false when the client is gone
     */
    public function write(string $chunk): bool;

    /**
     * Finishes the response, sending $content as the whole body when nothing was written.
     */
    public function end(string $content = ''): void;
}
