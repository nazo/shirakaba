<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * A handler result that knows how to become a response.
 *
 * Handlers return either a ResponseInterface or a ResponseValue; nothing else is converted
 * automatically (design/04-routing-and-handlers.md).
 */
interface ResponseValue
{
    public function toResponse(ResponseContext $context): ResponseInterface;
}
