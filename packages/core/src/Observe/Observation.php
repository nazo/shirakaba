<?php

declare(strict_types=1);

namespace Shirakaba\Core\Observe;

use Throwable;

/**
 * Events of one request, in order: matched (only when a route matches), handlerFinished
 * (only when the handler returned), failed and cleanupFailed (any number), responseReady
 * (always, last). recorded can come at any point before responseReady.
 *
 * "Response ready" means the kernel returned a response; when it has been sent is known
 * only to the runtime.
 */
interface Observation
{
    public function matched(RouteSummary $route): void;

    public function handlerFinished(): void;

    /**
     * An exception was turned into an error response. Expected HTTP failures are reported
     * too, so observers can tell client errors from server errors by the response status.
     */
    public function failed(Throwable $error): void;

    public function cleanupFailed(Throwable $error): void;

    /**
     * A package reported something that happened during the request through
     * RequestScope::record(), such as a database statement. Observers handle
     * the event classes they know and ignore the rest.
     */
    public function recorded(Event $event): void;

    public function responseReady(int $status): void;
}
