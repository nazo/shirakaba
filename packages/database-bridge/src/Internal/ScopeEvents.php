<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Internal;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Observe\DatabaseEvent;
use Shirakaba\Database\Observe\EventSink;
use Shirakaba\DatabaseBridge\RecordedDatabaseEvent;

/**
 * Records the events of a request's connections on its scope, for its observers.
 *
 * @internal
 */
final readonly class ScopeEvents implements EventSink
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    public function record(DatabaseEvent $event): void
    {
        $this->scope->record(new RecordedDatabaseEvent($event));
    }
}
