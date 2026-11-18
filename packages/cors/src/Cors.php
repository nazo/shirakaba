<?php

declare(strict_types=1);

namespace Shirakaba\Cors;

use InvalidArgumentException;
use Shirakaba\Http\Message\HttpFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-Origin Resource Sharing, configured in PHP:
 *
 *     $app->middleware(new Cors(['https://app.example.com'], allowedHeaders: ['Content-Type', 'Authorization']));
 *
 * Origins are compared exactly; ["*"] allows any origin but cannot be combined with
 * credentials. Preflight requests are answered here. As global middleware it also decorates
 * error responses, so browsers can read Problem Details from other origins.
 */
final readonly class Cors implements MiddlewareInterface
{
    private ResponseFactoryInterface $responses;

    /** @var array<string, true> */
    private array $origins;

    /** @var array<string, true> lower-cased */
    private array $headers;

    /**
     * @param list<string> $allowedOrigins exact origins such as "https://app.example.com", or ["*"]
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders request headers the client may send
     * @param list<string> $exposedHeaders response headers the client may read
     * @param int|null $maxAge seconds a preflight result may be cached
     * @throws InvalidArgumentException when "*" is combined with credentials or an origin is malformed
     */
    public function __construct(
        array $allowedOrigins,
        private array $allowedMethods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
        private array $allowedHeaders = ['Content-Type'],
        private array $exposedHeaders = [],
        private bool $allowCredentials = false,
        private ?int $maxAge = null,
        ?ResponseFactoryInterface $responses = null,
    ) {
        $any = in_array('*', $allowedOrigins, true);
        if ($any && $allowCredentials) {
            throw new InvalidArgumentException('CORS cannot allow credentials for any origin ("*"); list the origins.');
        }
        foreach ($allowedOrigins as $origin) {
            if ($origin !== '*' && preg_match('#^https?://[^/?\#\s]+$#D', $origin) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid origin "%s"; use "scheme://host[:port]" without a path.', $origin));
            }
        }

        $this->origins = array_fill_keys($allowedOrigins, true);
        $this->headers = array_fill_keys(array_map(strtolower(...), $allowedHeaders), true);
        $this->responses = $responses ?? new HttpFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $handler->handle($request);
        }

        $requestedMethod = $request->getHeaderLine('Access-Control-Request-Method');
        if ($request->getMethod() === 'OPTIONS' && $requestedMethod !== '') {
            return $this->preflight($origin, $requestedMethod, $request->getHeaderLine('Access-Control-Request-Headers'));
        }

        $response = $this->vary($handler->handle($request));
        if (!$this->allows($origin)) {
            return $response;
        }

        $response = $this->allowOrigin($response, $origin);
        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }

    private function preflight(string $origin, string $method, string $requestedHeaders): ResponseInterface
    {
        $response = $this->responses->createResponse(204)
            ->withHeader('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

        $headers = array_filter(
            array_map(static fn (string $header): string => strtolower(trim($header)), explode(',', $requestedHeaders)),
            static fn (string $header): bool => $header !== '',
        );
        $headersAllowed = array_all($headers, fn (string $header): bool => isset($this->headers[$header]));
        if (!$this->allows($origin) || !in_array($method, $this->allowedMethods, true) || !$headersAllowed) {
            // Without the allow headers the browser rejects the actual request.
            return $response;
        }

        $response = $this->allowOrigin($response, $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        if ($this->allowedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        }
        if ($this->maxAge !== null) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }

        return $response;
    }

    private function allows(string $origin): bool
    {
        return isset($this->origins['*']) || isset($this->origins[$origin]);
    }

    private function allowOrigin(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response->withHeader('Access-Control-Allow-Origin', isset($this->origins['*']) ? '*' : $origin);

        return $this->allowCredentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }

    /**
     * Caches must keep responses for different origins apart whenever the answer depends on it.
     */
    private function vary(ResponseInterface $response): ResponseInterface
    {
        if (isset($this->origins['*'])) {
            return $response;
        }
        $vary = array_map(strtolower(...), array_map(trim(...), explode(',', $response->getHeaderLine('Vary'))));

        return in_array('origin', $vary, true) ? $response : $response->withAddedHeader('Vary', 'Origin');
    }
}
