<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;

/**
 * Publishes messages that subscribers receive once the writer's transaction commits
 * (design/11-real-time-events.md). Outside a transaction they are sent at once; a rollback discards them, so a
 * subscriber never hears about data that was not stored.
 */
interface Publisher
{
    /**
     * @param string $channel letters, digits and underscores, starting with a letter or underscore
     * @throws \InvalidArgumentException when the channel name is invalid
     * @throws DatabaseError when sending fails
     * @phpstan-impure io.db
     */
    public function publish(Writer $db, string $channel, string $payload): void;
}
