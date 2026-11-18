<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use Shirakaba\Core\Description\ResponseDeclaration;
use Shirakaba\Core\Description\ResponseDescription;
use Shirakaba\Core\Scope\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A route's rate limit, made with RateLimiter::limit(). Declares its 429 so API descriptions
 * list it.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface, ResponseDeclaration
{
    /**
     * @internal made by RateLimiter::limit()
     */
    public function __construct(
        private RateLimiter $limiter,
        private string $name,
        private int $limit,
        private int $seconds,
        private RateKey $key,
        private bool $headers,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = RequestScope::of($request);
        $key = ($this->key->key)($request, $scope);
        if ($key === null) {
            return $handler->handle($request);
        }
        $result = $this->limiter->hit($scope, $this->name, $this->limit, $this->seconds, $key);
        $response = $handler->handle($request);
        if ($this->headers) {
            foreach ($result->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    public function responseDescription(): ResponseDescription
    {
        return new ResponseDescription(429, 'application/problem+json', description: sprintf('More than %d requests in %d seconds (%s).', $this->limit, $this->seconds, $this->name));
    }
}
