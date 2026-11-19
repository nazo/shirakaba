<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

use CurlHandle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Shirakaba\Core\Scope\RequestCancelled;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Http\Message\HttpFactory;

/**
 * A PSR-18 client for calling other servers (design/09-security.md), on ext-curl:
 *
 *     $client = new HttpClient(new HttpClientOptions(timeout: 5.0, prepare: $signer->sign(...)));
 *     $response = $client->in($scope)->sendRequest($request);
 *
 * Every host is resolved by the client and each address checked before connecting; curl is
 * then pinned to a checked address, so DNS rebinding cannot redirect it. Redirects are
 * followed by the client, each hop checked and prepared again, with credentials dropped when
 * the origin changes. The response body is limited, the whole exchange has one timeout, and
 * a client bound to a request scope stops when the request is cancelled or its deadline
 * passes, and reports every request to the scope's observers. Proxies from the environment
 * are ignored.
 */
final readonly class HttpClient implements ClientInterface
{
    /** @var list<string> */
    private const array CREDENTIALS = ['authorization', 'cookie', 'proxy-authorization'];

    public function __construct(
        private HttpClientOptions $options = new HttpClientOptions(),
        private Resolver $resolver = new SystemResolver(),
        private ResponseFactoryInterface $responses = new HttpFactory(),
        private StreamFactoryInterface $streams = new HttpFactory(),
        private UriFactoryInterface $uris = new HttpFactory(),
        private ?RequestScope $scope = null,
    ) {}

    /**
     * The same client bound to a request: it stops when the request is cancelled or its
     * deadline passes, and reports what it sends to the request's observers.
     */
    public function in(RequestScope $scope): self
    {
        return new self($this->options, $this->resolver, $this->responses, $this->streams, $this->uris, $scope);
    }

    /**
     * @throws RequestRefused when the target is not allowed or redirects too often
     * @throws NetworkError when the exchange fails, times out or the response is too large
     * @throws CallCancelled when the bound request is cancelled (a RequestCancelled)
     * @phpstan-impure io, nondet.time
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);
        if (!$request->hasHeader('User-Agent')) {
            $request = $request->withHeader('User-Agent', $this->options->userAgent);
        }
        for ($redirects = 0;; $redirects++) {
            $prepared = $this->options->prepare === null ? $request : ($this->options->prepare)($request);
            $response = $this->exchange($prepared, $started);
            $location = $response->getHeaderLine('Location');
            if (!in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true) || $location === '' || $this->options->maxRedirects === 0) {
                return $response;
            }
            if ($redirects >= $this->options->maxRedirects) {
                throw new RequestRefused(sprintf('More than %d redirects.', $this->options->maxRedirects), $prepared);
            }
            $request = $this->redirected($request, $response->getStatusCode(), $this->resolve($request->getUri(), $location));
        }
    }

    private function redirected(RequestInterface $request, int $status, UriInterface $target): RequestInterface
    {
        $from = $request->getUri();
        $next = $request->withUri($target);
        if ($from->getScheme() !== $target->getScheme() || $from->getHost() !== $target->getHost() || $from->getPort() !== $target->getPort()) {
            foreach (self::CREDENTIALS as $header) {
                $next = $next->withoutHeader($header);
            }
        }
        // Browsers turn a redirected POST into a GET for 301 and 302; 303 always means GET.
        if ($status === 303 || in_array($status, [301, 302], true) && $request->getMethod() === 'POST') {
            $next = $next
                ->withMethod($request->getMethod() === 'HEAD' ? 'HEAD' : 'GET')
                ->withBody($this->streams->createStream(''))
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length');
        }

        return $next;
    }

    /**
     * RFC 3986 reference resolution, enough for Location headers.
     */
    private function resolve(UriInterface $base, string $location): UriInterface
    {
        $target = $this->uris->createUri($location);
        if ($target->getScheme() !== '') {
            return $target;
        }
        if (str_starts_with($location, '//')) {
            return $this->uris->createUri($base->getScheme() . ':' . $location);
        }
        $path = $target->getPath();
        if ($path === '') {
            $path = $base->getPath();
        } elseif (!str_starts_with($path, '/')) {
            $directory = substr($base->getPath(), 0, (int) strrpos('/' . ltrim($base->getPath(), '/'), '/'));
            $path = rtrim($directory, '/') . '/' . $path;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = '/' . ltrim(implode('/', $segments), '/');

        return $base->withPath($path)->withQuery($target->getQuery())->withFragment('');
    }

    private function exchange(RequestInterface $request, int|float $started): ResponseInterface
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = trim($uri->getHost(), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RequestRefused(sprintf('Only http and https URLs with a host are allowed, not "%s".', (string) $uri), $request);
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $address = $this->address($host, $request);

        $remaining = $this->options->timeout - ((hrtime(true) - $started) / 1e9);
        $deadline = $this->scope?->deadline()?->remaining();
        if ($deadline !== null) {
            $remaining = min($remaining, $deadline);
        }
        if ($remaining <= 0) {
            $this->report($request, $host, $port, 0, 0.0, 'timeout');
            throw new NetworkError('The request timed out before it was sent.', $request, timedOut: true);
        }

        $url = (string) $uri->withFragment('');
        $handle = curl_init();
        if ($handle === false || $url === '') {
            throw new NetworkError('Unable to start a curl handle.', $request);
        }
        $status = 0;
        $protocol = '1.1';
        /** @var array<string, list<string>> $headers */
        $headers = [];
        $body = '';
        $tooLarge = false;
        $limit = $this->options->maxResponseBytes;
        $cancellation = $this->scope?->cancellation();
        $outgoing = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $outgoing[] = $name . ': ' . $value;
            }
        }
        $outgoing[] = 'Expect:';
        $requestBody = (string) $request->getBody();
        $method = strtoupper($request->getMethod());
        if ($method === '') {
            throw new RequestRefused('The request has no method.', $request);
        }
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => $outgoing,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT_MS => max(1, (int) ($remaining * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) (min($this->options->connectTimeout, $remaining) * 1000)),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$status, &$protocol, &$headers): int {
                if (preg_match('#^HTTP/(\d(?:\.\d)?) (\d{3})#', $line, $match) === 1) {
                    // A new header block (after 100 Continue, for example) replaces the last.
                    $protocol = $match[1];
                    $status = (int) $match[2];
                    $headers = [];
                } else {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[trim($parts[0])][] = trim($parts[1]);
                    }
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body, &$tooLarge, $limit): int {
                if ((strlen($body) + strlen($chunk)) > $limit) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_XFERINFOFUNCTION => static fn (CurlHandle $handle, int $downloadTotal): int => $cancellation?->isRequested() === true
                || $downloadTotal > $limit
                    ? 1
                    : 0,
        ]);
        if ($requestBody !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $requestBody);
        }
        curl_setopt($handle, CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? '[' . $address . ']' : $address)]);

        $sent = hrtime(true);
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $downloadTotal = curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $seconds = (hrtime(true) - $sent) / 1e9;

        if ($ok === false) {
            if ($cancellation?->isRequested() === true) {
                $this->report($request, $host, $port, $status, $seconds, 'cancelled');
                throw new CallCancelled('The request was cancelled while calling ' . $host . '.');
            }
            $tooLarge = $tooLarge || is_float($downloadTotal) && $downloadTotal > $limit;
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            $this->report($request, $host, $port, $status, $seconds, $tooLarge ? 'response_too_large' : ($timedOut ? 'timeout' : 'network'));
            throw new NetworkError(
                $tooLarge ? sprintf('The response from %s exceeds %d bytes.', $host, $limit) : sprintf('Calling %s failed: %s', $host, $error),
                $request,
                timedOut: $timedOut,
                tooLarge: $tooLarge,
            );
        }
        $this->report($request, $host, $port, $status, $seconds, '');

        $response = $this->responses->createResponse($status)->withProtocolVersion($protocol)->withBody($this->streams->createStream($body));
        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    /**
     * The checked address to pin the connection to.
     */
    private function address(string $host, RequestInterface $request): string
    {
        $allow = $this->options->allowAddress ?? Addresses::isPublic(...);
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new RequestRefused(sprintf('The host "%s" does not resolve.', $host), $request);
        }
        foreach ($addresses as $address) {
            // One refused address refuses the host: a name that mixes public and private
            // addresses is a rebinding attempt or a misconfiguration.
            if (!$allow($address)) {
                throw new RequestRefused(sprintf('The host "%s" resolves to %s, which this client may not connect to.', $host, $address), $request);
            }
        }

        return $addresses[0];
    }

    private function report(RequestInterface $request, string $host, int $port, int $status, float $seconds, string $error): void
    {
        $this->scope?->record(
            new HttpRequestSent(strtoupper($request->getMethod()), strtolower($request->getUri()->getScheme()), $host, $port, $status, $seconds, $error),
        );
    }
}
