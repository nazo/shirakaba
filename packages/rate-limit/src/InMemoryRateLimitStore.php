<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use DateTimeImmutable;
use Shirakaba\Core\Scope\RequestScope;

/**
 * Counts hits in memory, for tests and single-process tools.
 */
final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{int, int}> bucket and window => hits, expiry timestamp */
    private array $counts = [];

    public function hit(RequestScope $scope, string $bucket, DateTimeImmutable $window, DateTimeImmutable $expiresAt): int
    {
        $now = $window->getTimestamp();
        $this->counts = array_filter($this->counts, static fn (array $count): bool => $count[1] > $now);
        $key = $bucket . '@' . $window->getTimestamp();
        $hits = ($this->counts[$key][0] ?? 0) + 1;
        $this->counts[$key] = [$hits, $expiresAt->getTimestamp()];

        return $hits;
    }
}
