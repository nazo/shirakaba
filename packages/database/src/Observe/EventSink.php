<?php

declare(strict_types=1);

namespace Shirakaba\Database\Observe;

/**
 * Receives the events of one lease or connection (design/08-database.md). shirakaba/database-bridge passes
 * one that records them on the request; scripts can pass their own, or none.
 */
interface EventSink
{
    public function record(DatabaseEvent $event): void;
}
