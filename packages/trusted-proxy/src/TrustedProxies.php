<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies forwarding headers, but only from trusted proxies and only the headers named
 * (design/03-runtimes-and-scopes.md):
 *
 *     $app->middleware(new TrustedProxies(
 *         ['10.0.0.0/8', '::1'],
 *         [ForwardedHeader::For, ForwardedHeader::Proto],
 *     ));
 *
 * Name only headers that the proxy sets or overwrites; any other header still carries
 * what the client sent. There is no default, because a wrong guess lets clients choose
 * their address or the host used in absolute URLs.
 *
 * When the direct peer (REMOTE_ADDR) is trusted, the forwarding chain is walked from the
 * nearest hop; the first untrusted address is the client. Scheme, host and port come from
 * the hop that received the client's request: in `Forwarded` (RFC 7239) that is the
 * element naming the client; with `X-Forwarded-*` the last value, which the nearest proxy
 * set. Untrusted or malformed input changes nothing. The client address is recorded as a
 * ClientAddress request attribute.
 *
 * Add it as the first global middleware so everything after it sees the corrected request.
 */
final readonly class TrustedProxies implements MiddlewareInterface
{
    /** @var list<IpRange> */
    private array $trusted;

    /** @var array<string, true> names of the trusted ForwardedHeader cases */
    private array $headers;

    /**
     * @param list<string> $proxies addresses or CIDR networks of the proxies in front of the application
     * @param list<ForwardedHeader> $headers the headers those proxies set or overwrite; at least one
     * @throws InvalidArgumentException when an entry is not an address or network, no header
     *         is named, or Forwarded is combined with X-Forwarded-* headers
     */
    public function __construct(array $proxies, array $headers)
    {
        $this->trusted = array_map(IpRange::parse(...), $proxies);
        if ($headers === []) {
            throw new InvalidArgumentException('Name the forwarding headers the proxies set, such as [ForwardedHeader::For].');
        }
        $names = [];
        foreach ($headers as $header) {
            $names[$header->name] = true;
        }
        if (isset($names[ForwardedHeader::Forwarded->name]) && count($names) > 1) {
            throw new InvalidArgumentException('Trust either the Forwarded header or X-Forwarded-* headers, not both.');
        }
        $this->headers = $names;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || !$this->isTrusted($remote)) {
            return $handler->handle(is_string($remote) ? $request->withAttribute(ClientAddress::class, new ClientAddress($remote)) : $request);
        }

        [$client, $scheme, $host] = isset($this->headers[ForwardedHeader::Forwarded->name])
            ? $this->fromForwarded($request->getHeaderLine('Forwarded'), $remote)
            : $this->fromXForwarded($request, $remote);

        $request = $request->withAttribute(ClientAddress::class, new ClientAddress($client));
        $uri = $this->applyTo($request->getUri(), $scheme, $host);

        return $handler->handle($uri === $request->getUri() ? $request : $request->withUri($uri, preserveHost: false));
    }

    private function isTrusted(string $address): bool
    {
        foreach ($this->trusted as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, ?string, ?string} client, scheme, host
     */
    private function fromForwarded(string $header, string $remote): array
    {
        if ($header === '') {
            return [$remote, null, null];
        }
        $elements = [];
        foreach (explode(',', $header) as $element) {
            $pairs = [];
            foreach (explode(';', $element) as $pair) {
                $pair = trim($pair);
                $equals = strpos($pair, '=');
                if ($equals !== false) {
                    $pairs[strtolower(substr($pair, 0, $equals))] = trim(substr($pair, $equals + 1), '"');
                }
            }
            $elements[] = $pairs;
        }

        $client = $remote;
        $scheme = null;
        $host = null;
        foreach (array_reverse($elements) as $pairs) {
            $for = self::forAddress($pairs['for'] ?? '');
            if ($for === null) {
                break;
            }
            $client = $for;
            $scheme = $pairs['proto'] ?? null;
            $host = $pairs['host'] ?? null;
            if (!$this->isTrusted($for)) {
                break;
            }
        }

        return [$client, $scheme, $host];
    }

    /**
     * @return array{string, ?string, ?string} client, scheme, host
     */
    private function fromXForwarded(ServerRequestInterface $request, string $remote): array
    {
        $client = $remote;
        $chain = isset($this->headers[ForwardedHeader::For->name])
            ? array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For')))
            : [];
        foreach (array_reverse($chain) as $address) {
            if (@inet_pton($address) === false) {
                break;
            }
            $client = $address;
            if (!$this->isTrusted($address)) {
                break;
            }
        }

        $last = static function (string $value): ?string {
            $values = array_map(trim(...), explode(',', $value));
            $final = end($values);

            return $final === false || $final === '' ? null : $final;
        };
        $trusted = fn (ForwardedHeader $header, string $name): ?string => isset($this->headers[$header->name])
            ? $last($request->getHeaderLine($name))
            : null;
        $host = $trusted(ForwardedHeader::Host, 'X-Forwarded-Host');
        $port = $trusted(ForwardedHeader::Port, 'X-Forwarded-Port');
        if ($host !== null && $port !== null && ctype_digit($port) && !str_contains(ltrim($host, '['), ':')) {
            $host .= ':' . $port;
        }

        return [$client, $trusted(ForwardedHeader::Proto, 'X-Forwarded-Proto'), $host];
    }

    /**
     * Decodes a Forwarded "for" value: an address, "[v6]:port", or "unknown"/obfuscated.
     */
    private static function forAddress(string $value): ?string
    {
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/D', $value, $match) === 1) {
            $value = $match[1];
        } elseif (substr_count($value, ':') === 1) {
            $value = substr($value, 0, (int) strpos($value, ':'));
        }

        return @inet_pton($value) === false ? null : $value;
    }

    private function applyTo(UriInterface $uri, ?string $scheme, ?string $host): UriInterface
    {
        try {
            if ($scheme !== null && in_array(strtolower($scheme), ['http', 'https'], true)) {
                $uri = $uri->withScheme(strtolower($scheme))->withPort(null);
            }
            if ($host !== null && preg_match('/^(\[[0-9a-fA-F:.]+\]|[^:\[\]]+)(?::(\d{1,5}))?$/D', $host, $match) === 1) {
                $uri = $uri->withHost($match[1])->withPort(isset($match[2]) ? (int) $match[2] : null);
            }
        } catch (InvalidArgumentException) {
            // A malformed forwarded value is ignored rather than trusted halfway.
        }

        return $uri;
    }
}
