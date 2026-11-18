<?php

declare(strict_types=1);

namespace Shirakaba\HttpClient;

/**
 * Resolves with the system: A and AAAA records from DNS, plus the hosts file.
 */
final readonly class SystemResolver implements Resolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        $hosts = @gethostbynamel($host);
        foreach (is_array($hosts) ? $hosts : [] as $address) {
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }
}
