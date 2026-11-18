<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 address or CIDR network.
 */
final readonly class IpRange
{
    private function __construct(
        private string $network,
        private int $bits,
    ) {}

    /**
     * @throws InvalidArgumentException when the value is not an address or CIDR network
     */
    public static function parse(string $range): self
    {
        $slash = strpos($range, '/');
        $address = $slash === false ? $range : substr($range, 0, $slash);
        $prefix = $slash === false ? null : substr($range, $slash + 1);
        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException(sprintf('Invalid IP address or network "%s".', $range));
        }
        $max = strlen($packed) * 8;
        $bits = $prefix === null ? $max : (ctype_digit($prefix) ? (int) $prefix : -1);
        if ($bits < 0 || $bits > $max) {
            throw new InvalidArgumentException(sprintf('Invalid prefix length in "%s".', $range));
        }

        return new self($packed, $bits);
    }

    public function contains(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== strlen($this->network)) {
            return false;
        }

        $bytes = intdiv($this->bits, 8);
        if (substr($packed, 0, $bytes) !== substr($this->network, 0, $bytes)) {
            return false;
        }
        $remainder = $this->bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($this->network[$bytes]) & $mask);
    }
}
