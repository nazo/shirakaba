<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Http\Message\HttpFactory;

/**
 * Optional middleware that serves a document generated ahead of time (design/06-json-and-openapi.md):
 *
 *     $app->middleware(new ServeDocument(__DIR__ . '/openapi.json'));
 *
 * The file is read once, when the middleware is created; nothing is generated per request.
 */
final readonly class ServeDocument implements MiddlewareInterface
{
    private string $document;
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /**
     * @throws InvalidArgumentException when the file cannot be read
     */
    public function __construct(
        string $file,
        private string $path = '/openapi.json',
        ?ResponseFactoryInterface $responses = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $document = is_file($file) ? file_get_contents($file) : false;
        if ($document === false) {
            throw new InvalidArgumentException(sprintf('Unable to read the OpenAPI document "%s".', $file));
        }
        $this->document = $document;
        $factory = new HttpFactory();
        $this->responses = $responses ?? $factory;
        $this->streams = $streams ?? $factory;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->path || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        return $this->responses
            ->createResponse()
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($request->getMethod() === 'HEAD' ? '' : $this->document));
    }
}
