<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The default error renderer: RFC 9457 Problem Details as application/problem+json
 * (design/05-errors-and-observability.md).
 */
final readonly class ProblemDetails implements ErrorRenderer
{
    public function __construct(
        private ResponseContext $context,
    ) {}

    public function render(Failure $failure, ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->context->responses->createResponse($failure->status);
        $document = [
            'type' => $failure->type,
            'title' => $failure->title !== '' ? $failure->title : $response->getReasonPhrase(),
            'status' => $failure->status,
        ];
        if ($failure->detail !== '') {
            $document['detail'] = $failure->detail;
        }
        // RFC 9457 members always come from the failure itself.
        $document += array_diff_key($failure->extensions, array_flip(['type', 'title', 'status', 'detail', 'instance']));

        $json = json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $response
            ->withHeader('Content-Type', 'application/problem+json')
            ->withBody($this->context->streams->createStream($json === false ? '{}' : $json));
    }
}
