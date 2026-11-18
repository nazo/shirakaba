<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\ResponseDescription;

/**
 * The result of planning one handler: the invoker for requests and the description for
 * metadata (design/04-routing-and-handlers.md). Both come from the same pass, so they cannot drift.
 *
 * @internal
 */
final readonly class HandlerPlan
{
    /**
     * @param list<ParameterDescription> $parameters
     * @param list<string> $undescribed
     * @param list<class-string> $responses
     * @param list<ResponseDescription> $declaredResponses
     */
    public function __construct(
        public HandlerInvoker $invoker,
        public array $parameters,
        public array $undescribed,
        public array $responses,
        public array $declaredResponses = [],
    ) {}
}
