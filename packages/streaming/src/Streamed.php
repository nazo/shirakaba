<?php

declare(strict_types=1);

namespace Shirakaba\Streaming;

use Psr\Http\Message\ResponseInterface;
use Shirakaba\Core\Description\DescribesResponse;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Http\ResponseContext;
use Shirakaba\Core\Http\ResponseValue;

/**
 * A response whose body is produced chunk by chunk, such as a large export:
 *
 *     return new Streamed($exporter->csvRows(), 'text/csv; charset=utf-8');
 */
final readonly class Streamed implements ResponseValue, DescribesResponse
{
    /**
     * @param iterable<string> $chunks
     * @param array<string, string> $headers
     */
    public function __construct(
        public iterable $chunks,
        public string $contentType = 'application/octet-stream',
        public int $status = 200,
        public array $headers = [],
    ) {}

    public function toResponse(ResponseContext $context): ResponseInterface
    {
        $response = $context
            ->responses
            ->createResponse($this->status)
            ->withHeader('Content-Type', $this->contentType)
            ->withBody(new GeneratorStream($this->chunks));
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public static function describeResponse(): ResponseDescription
    {
        return new ResponseDescription(200, 'application/octet-stream', new ScalarType('string'));
    }
}
