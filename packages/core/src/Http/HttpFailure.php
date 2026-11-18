<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A deliberate HTTP error, rendered as RFC 9457 Problem Details (design/05-errors-and-observability.md).
 *
 * Everything in it may be shown to clients, so it must not carry internal details.
 * Applications may extend it for their own problem types.
 */
class HttpFailure extends RuntimeException
{
    /**
     * @param int<400, 599> $status
     * @param string $detail explanation meant for clients; empty to omit
     * @param string $type a URI identifying the problem type
     * @param string $title short summary; empty to use the status's reason phrase
     * @param array<string, string> $headers headers the response must carry, such as WWW-Authenticate
     * @param array<string, mixed> $extensions additional Problem Details members, such as a list of
     *                                         field errors; members defined by RFC 9457 cannot be overridden
     */
    public function __construct(
        public readonly int $status,
        public readonly string $detail = '',
        public readonly string $type = 'about:blank',
        public readonly string $title = '',
        public readonly array $headers = [],
        public readonly array $extensions = [],
        ?Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(sprintf('An HTTP failure needs a 4xx or 5xx status, %d given.', $status));
        }

        parent::__construct($detail !== '' ? $detail : sprintf('HTTP %d', $status), 0, $previous);
    }

    public static function badRequest(string $detail = ''): self
    {
        return new self(400, $detail);
    }

    /**
     * @param string $challenge the WWW-Authenticate value, such as 'Bearer realm="api"'
     */
    public static function unauthorized(string $challenge, string $detail = ''): self
    {
        return new self(401, $detail, headers: ['WWW-Authenticate' => $challenge]);
    }

    public static function forbidden(string $detail = ''): self
    {
        return new self(403, $detail);
    }

    public static function notFound(string $detail = ''): self
    {
        return new self(404, $detail);
    }

    public static function conflict(string $detail = ''): self
    {
        return new self(409, $detail);
    }
}
