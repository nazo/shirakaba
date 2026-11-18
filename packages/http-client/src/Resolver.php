<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

/**
 * Turns a host name into the IP addresses the client may connect to. The client checks every
 * address and connects only to a checked one, so DNS cannot point it elsewhere afterwards.
 */
interface Resolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the host does not resolve
     */
    public function resolve(string $host): array;
}
