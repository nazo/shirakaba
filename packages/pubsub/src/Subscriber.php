<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Error\DatabaseError;

/**
 * Subscribes to channels for the life of a request scope (design/11-real-time-events.md). The subscription ends
 * when the scope closes, which for an Sse response is when the stream ends.
 */
interface Subscriber
{
    /**
     * @param non-empty-list<string>|string $channels
     * @throws \InvalidArgumentException when a channel name is invalid
     * @throws DatabaseError when subscribing fails
     * @phpstan-impure io.db
     */
    public function subscribe(RequestScope $scope, string|array $channels): Subscription;
}
