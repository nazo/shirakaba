<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Shirakaba\Core\Description\DescribesResponse;
use Shirakaba\Core\Description\ResponseDescription;
use Psr\Http\Message\ResponseInterface;

/**
 * A 204 No Content response.
 */
final readonly class NoContent implements ResponseValue, DescribesResponse
{
    public function toResponse(ResponseContext $context): ResponseInterface
    {
        return $context->responses->createResponse(204);
    }

    public static function describeResponse(): ResponseDescription
    {
        return new ResponseDescription(204);
    }
}
