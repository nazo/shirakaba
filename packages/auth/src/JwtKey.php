<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * A verification key bound to exactly one algorithm (design/09-security.md). The token's "alg" header
 * must match it; it never chooses the algorithm.
 */
final readonly class JwtKey
{
    /**
     * @param 'HS256'|'RS256'|'ES256' $algorithm
     */
    private function __construct(
        public string $algorithm,
        private string|OpenSSLAsymmetricKey $material,
        public ?string $id,
    ) {}

    /**
     * @throws InvalidArgumentException when the secret is shorter than 32 bytes
     */
    public static function hmac(string $secret, ?string $id = null): self
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('An HS256 secret must be at least 32 bytes long.');
        }

        return new self('HS256', $secret, $id);
    }

    /**
     * @throws InvalidArgumentException when the PEM is not an RSA public key of at least 2048 bits
     */
    public static function rsa(string $publicKeyPem, ?string $id = null): self
    {
        $key = self::publicKey($publicKeyPem, OPENSSL_KEYTYPE_RSA);
        $details = openssl_pkey_get_details($key);
        $bits = is_array($details) ? ($details['bits'] ?? 0) : 0;
        if (!is_int($bits) || $bits < 2048) {
            throw new InvalidArgumentException('An RS256 key must have at least 2048 bits.');
        }

        return new self('RS256', $key, $id);
    }

    /**
     * @throws InvalidArgumentException when the PEM is not a P-256 public key
     */
    public static function ec(string $publicKeyPem, ?string $id = null): self
    {
        $key = self::publicKey($publicKeyPem, OPENSSL_KEYTYPE_EC);
        $details = openssl_pkey_get_details($key);
        $ec = is_array($details) ? ($details['ec'] ?? null) : null;
        if (!is_array($ec) || ($ec['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('An ES256 key must be on the P-256 curve.');
        }

        return new self('ES256', $key, $id);
    }

    /**
     * @internal
     */
    public function verifies(string $input, string $signature): bool
    {
        if (is_string($this->material)) {
            return hash_equals(hash_hmac('sha256', $input, $this->material, true), $signature);
        }
        if ($this->algorithm === 'ES256') {
            $der = self::rawToDer($signature);
            if ($der === null) {
                return false;
            }
            $signature = $der;
        }

        return openssl_verify($input, $signature, $this->material, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function publicKey(string $pem, int $type): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InvalidArgumentException('Unable to read the public key.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== $type) {
            throw new InvalidArgumentException('The public key has the wrong type.');
        }

        return $key;
    }

    /**
     * JWS ES256 signatures are r || s (32 bytes each); OpenSSL expects DER.
     */
    private static function rawToDer(string $signature): ?string
    {
        if (strlen($signature) !== 64) {
            return null;
        }
        $integer = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\0" . $bytes;
            }

            // At most 33 bytes, so the short length form applies.
            return "\x02" . chr(strlen($bytes) & 0xFF) . $bytes;
        };
        $sequence = $integer(substr($signature, 0, 32)) . $integer(substr($signature, 32));

        return "\x30" . chr(strlen($sequence) & 0xFF) . $sequence;
    }
}
