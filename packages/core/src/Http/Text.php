<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Shirakaba\Core\Description\DescribesResponse;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Description\ScalarType;
use Psr\Http\Message\ResponseInterface;

/**
 * A plain text response.
 */
final readonly class Text implements ResponseValue, DescribesResponse
{
    public function __construct(
        public string $body,
        /** Null for the route's declared success status, or 200. */
        public ?int $status = null,
        public string $contentType = 'text/plain; charset=utf-8',
    ) {}

    public function toResponse(ResponseContext $context): ResponseInterface
    {
        return $context->responses->createResponse($context->status($this->status))
            ->withHeader('Content-Type', $this->contentType)
            ->withBody($context->streams->createStream($this->body));
    }

    public static function describeResponse(): ResponseDescription
    {
        return new ResponseDescription(200, 'text/plain', new ScalarType('string'));
    }
}
