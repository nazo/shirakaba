<?php

declare(strict_types=1);

namespace Shirakaba\Auth\Tests\Fixtures;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Issues tokens for tests (Shirakaba itself does not issue tokens).
 */
final class Signer
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function token(array $header, array $claims, string|OpenSSLAsymmetricKey $key): string
    {
        $input = self::encode((string) json_encode($header)) . '.' . self::encode((string) json_encode($claims));
        $algorithm = $header['alg'] ?? null;
        $signature = match (true) {
            is_string($key) => hash_hmac('sha256', $input, $key, true),
            default => self::openssl($input, $key, $algorithm === 'ES256'),
        };

        return $input . '.' . self::encode($signature);
    }

    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function openssl(string $input, OpenSSLAsymmetricKey $key, bool $ec): string
    {
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256) || !is_string($der)) {
            throw new RuntimeException('Signing failed.');
        }
        if (!$ec) {
            return $der;
        }
        // DER SEQUENCE { INTEGER r, INTEGER s } to r || s, 32 bytes each.
        $offset = 2;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return implode('', $parts);
    }
}
