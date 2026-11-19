<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shirakaba\Core\Http\HttpFailure;

/**
 * Answers 400 to requests for a host the application does not serve (design/03-runtimes-and-scopes.md):
 *
 *     $app->middleware(
 *         new TrustedProxies(['10.0.0.0/8'], [ForwardedHeader::For, ForwardedHeader::Proto]),
 *         new AllowedHosts(['app.example.com', '*.example.com']),
 *     );
 *
 * The host is read from the request URI, so place it after TrustedProxies. Clients choose
 * the Host header; without this check, anything built from the request URI (absolute
 * links, redirects, cache keys) can name another host. Ports are not compared.
 *
 * "*.example.com" matches any subdomain of example.com, but not example.com itself.
 */
final readonly class AllowedHosts implements MiddlewareInterface
{
    /** @var array<string, true> */
    private array $exact;

    /** @var list<string> suffixes such as ".example.com" */
    private array $suffixes;

    /**
     * @param list<string> $hosts names, "[v6]" addresses, or "*.domain" patterns; at least one
     * @throws InvalidArgumentException when the list is empty or a pattern is malformed
     */
    public function __construct(array $hosts)
    {
        if ($hosts === []) {
            throw new InvalidArgumentException('AllowedHosts needs at least one host.');
        }
        $exact = [];
        $suffixes = [];
        foreach ($hosts as $host) {
            $host = strtolower($host);
            $name = str_starts_with($host, '*.') ? substr($host, 2) : $host;
            if (preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/D', $name) !== 1 || str_contains($name, '*')) {
                throw new InvalidArgumentException(sprintf('Invalid allowed host "%s"; use "name", "[v6]" or "*.domain" without a port.', $host));
            }
            if ($name === $host) {
                $exact[$host] = true;
            } else {
                $suffixes[] = '.' . $name;
            }
        }
        $this->exact = $exact;
        $this->suffixes = $suffixes;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->allows($request->getUri()->getHost())) {
            throw HttpFailure::badRequest('The requested host is not served here.');
        }

        return $handler->handle($request);
    }

    public function allows(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === '') {
            return false;
        }
        if (isset($this->exact[$host])) {
            return true;
        }

        return array_any($this->suffixes, static fn (string $suffix): bool => str_ends_with($host, $suffix) && strlen($host) > strlen($suffix));
    }
}
