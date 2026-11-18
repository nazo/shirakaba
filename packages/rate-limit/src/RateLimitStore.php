<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use DateTimeImmutable;
use Shirakaba\Core\Scope\RequestScope;

/**
 * Counts hits per bucket and window (design/09-security.md). A store shared by all processes (the database)
 * enforces a limit across servers; the in-memory one is for tests.
 */
interface RateLimitStore
{
    /**
     * Counts one hit and returns the number of hits in the window, this one included.
     *
     * @param string $bucket the limit's name and a digest of its key
     * @param DateTimeImmutable $expiresAt when the count may be forgotten
     */
    public function hit(RequestScope $scope, string $bucket, DateTimeImmutable $window, DateTimeImmutable $expiresAt): int;
}
