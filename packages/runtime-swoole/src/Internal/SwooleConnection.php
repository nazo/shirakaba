<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\Swoole\Internal;

use Swoole\Http\Response;

/**
 * @internal
 */
final readonly class SwooleConnection implements Connection
{
    public function __construct(
        private Response $response,
    ) {}

    public function status(int $code, string $reason): void
    {
        $this->response->status($code, $reason);
    }

    /**
     * @param list<string> $values
     */
    public function header(string $name, array $values): void
    {
        // Unformatted: the application's spelling of the name is kept.
        $this->response->header($name, $values, false);
    }

    public function write(string $chunk): bool
    {
        // Swoole warns when the connection is gone; the false result says the same.
        return @$this->response->write($chunk);
    }

    public function end(string $content = ''): void
    {
        @$this->response->end($content);
    }
}
