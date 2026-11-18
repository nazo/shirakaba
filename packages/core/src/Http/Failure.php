<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Throwable;

/**
 * An error as handed to an ErrorRenderer: only what may be shown to clients (design/05-errors-and-observability.md).
 *
 * Unexpected throwables are turned into a bare 500 before a renderer sees them, so no
 * renderer can leak their message, class or trace.
 */
final readonly class Failure
{
    /**
     * @param int<400, 599> $status
     * @param string $title empty to use the status's reason phrase
     * @param array<string, string> $headers headers the response must carry; re-applied after rendering
     * @param array<string, mixed> $extensions additional members, such as field errors
     */
    public function __construct(
        public int $status,
        public string $detail = '',
        public string $type = 'about:blank',
        public string $title = '',
        public array $headers = [],
        public array $extensions = [],
    ) {}

    public static function fromThrowable(Throwable $error): self
    {
        if ($error instanceof HttpFailure) {
            return new self($error->status, $error->detail, $error->type, $error->title, $error->headers, $error->extensions);
        }

        return new self(500);
    }
}
