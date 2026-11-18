<?php

declare(strict_types=1);

namespace Shirakaba\Core\Observe;

/**
 * Receives a call for every request and returns the observation that follows it (design/05-errors-and-observability.md).
 *
 * Observers are passed to the application explicitly. Exceptions thrown by an observer or
 * an observation are swallowed and never change the response.
 */
interface Observer
{
    public function begin(RequestSummary $request): Observation;
}
