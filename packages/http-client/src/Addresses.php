<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

/**
 * The default address policy: only globally routable addresses (RFC 6890), so a URL from
 * outside cannot reach loopback, private networks, link-local addresses such as cloud
 * metadata endpoints, or other special ranges. IPv4 embedded in IPv6 (mapped, NAT64) is
 * judged by the IPv4 address.
 */
final readonly class Addresses
{
    public static function isPublic(string $address): bool
    {
        $address = strtolower(trim($address, '[]'));
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($address);
            if ($packed === false) {
                return false;
            }
            $prefix = substr($packed, 0, 12);
            // ::ffff:a.b.c.d (mapped), 64:ff9b::a.b.c.d (NAT64) and ::a.b.c.d (compatible).
            if (in_array($prefix, [str_repeat("\0", 10) . "\xff\xff", "\0\x64\xff\x9b" . str_repeat("\0", 8), str_repeat("\0", 12)], true)) {
                $embedded = inet_ntop(substr($packed, 12));
                if ($embedded === false) {
                    return false;
                }
                $address = $embedded;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }
}
