<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use DateTimeImmutable;

/**
 * The outcome of one hit against a limit.
 */
final readonly class RateLimitResult
{
    public function __construct(
        public string $name,
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $windowSeconds,
        /** When the current window ends and the count starts again. */
        public DateTimeImmutable $resetAt,
        /** Seconds until the window resets, at least 1. */
        public int $retryAfter,
    ) {}

    /**
     * The IETF RateLimit and RateLimit-Policy headers (draft-ietf-httpapi-ratelimit-headers).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'RateLimit' => sprintf('"%s";r=%d;t=%d', $this->name, $this->remaining, $this->retryAfter),
            'RateLimit-Policy' => sprintf('"%s";q=%d;w=%d', $this->name, $this->limit, $this->windowSeconds),
        ];
    }
}
