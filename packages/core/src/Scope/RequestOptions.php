<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

/**
 * What a runtime passes to Application::handleWith() for one request.
 */
final readonly class RequestOptions
{
    public function __construct(
        public Cancellation $cancellation = new Cancellation(),
        public ?Deadline $deadline = null,
    ) {}
}
