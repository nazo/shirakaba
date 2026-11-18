<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\ProblemCode;

/**
 * A 429 with Retry-After and the RateLimit headers, code `rate-limited`.
 */
final class TooManyRequests extends HttpFailure
{
    public function __construct(
        public readonly RateLimitResult $result,
    ) {
        parent::__construct(
            429,
            sprintf('Too many requests; try again in %d seconds.', $result->retryAfter),
            headers: ['Retry-After' => (string) $result->retryAfter, ...$result->headers()],
            extensions: ['code' => ProblemCode::RATE_LIMITED],
        );
    }
}
