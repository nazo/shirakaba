<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Internal;

use Shirakaba\PubSub\Message;

/**
 * @internal
 */
interface Receiver
{
    /**
     * The next message, waiting up to $timeoutMilliseconds, or null when none arrived.
     */
    public function receive(int $timeoutMilliseconds): ?Message;

    public function close(): void;
}
