<?php

declare(strict_types=1);

namespace Shirakaba\Auth;

use Closure;
use InvalidArgumentException;
use JsonException;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Shirakaba's minimal JWT (JWS compact) verifier: HS256, RS256 and ES256 (design/09-security.md).
 *
 *     new JwtVerifier(
 *         [JwtKey::rsa($pem, 'key-1')],
 *         static fn (array $claims, RequestScope $scope): ?User => new Users(RequestDatabase::reader($scope->get(Database::class), $scope))->find($claims['sub'] ?? ''),
 *         issuer: 'https://auth.example.com',
 *         audience: 'api',
 *     );
 */
final readonly class JwtVerifier implements TokenVerifier
{
    /**
     * @param non-empty-list<JwtKey> $keys
     * @param Closure(array<string, mixed>, RequestScope): ?object $principal maps verified claims to a
     *        principal, with the request's scope for looking it up
     * @param ClockInterface $clock defaults to the UTC system clock
     * @throws InvalidArgumentException when no key is given or the leeway is negative
     */
    public function __construct(
        private array $keys,
        private Closure $principal,
        private ?string $issuer = null,
        private ?string $audience = null,
        private int $leeway = 60,
        private bool $requireExpiration = true,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if ($keys === [] || $leeway < 0) {
            throw new InvalidArgumentException('A JWT verifier needs at least one key and a non-negative leeway.');
        }
    }

    public function verify(string $token, RequestScope $scope): ?object
    {
        $claims = $this->claims($token);

        return $claims === null ? null : ($this->principal)($claims, $scope);
    }

    /**
     * Verifies the signature and the standard claims.
     *
     * @return array<string, mixed>|null the claims, or null when the token is invalid
     */
    public function claims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = self::object($encodedHeader);
        $claims = self::object($encodedClaims);
        $signature = self::decode($encodedSignature);
        if ($header === null || $claims === null || $signature === null) {
            return null;
        }
        if (isset($header['crit']) || (isset($header['typ']) && (!is_string($header['typ']) || strcasecmp($header['typ'], 'JWT') !== 0))) {
            return null;
        }

        $key = $this->key($header);
        if ($key === null || !$key->verifies($encodedHeader . '.' . $encodedClaims, $signature)) {
            return null;
        }

        return $this->acceptable($claims) ? $claims : null;
    }

    /**
     * @param array<string, mixed> $header
     */
    private function key(array $header): ?JwtKey
    {
        $algorithm = $header['alg'] ?? null;
        $id = $header['kid'] ?? null;
        foreach ($this->keys as $key) {
            if ($key->algorithm !== $algorithm) {
                continue;
            }
            if ($key->id === null || $key->id === $id) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function acceptable(array $claims): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $exp = $claims['exp'] ?? null;
        $nbf = $claims['nbf'] ?? null;
        $iat = $claims['iat'] ?? null;
        if ($exp === null ? $this->requireExpiration : (!is_int($exp) || $now - $this->leeway >= $exp)) {
            return false;
        }
        if ($nbf !== null && (!is_int($nbf) || $now + $this->leeway < $nbf)) {
            return false;
        }
        if ($iat !== null && (!is_int($iat) || $now + $this->leeway < $iat)) {
            return false;
        }
        if ($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) {
            return false;
        }
        if ($this->audience !== null) {
            // RFC 7519: a string or an array of strings. A JSON object is neither.
            $audience = $claims['aud'] ?? null;
            $audiences = is_array($audience) && array_is_list($audience) ? $audience : [$audience];
            if (!in_array($this->audience, $audiences, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function object(string $encoded): ?array
    {
        $json = self::decode($encoded);
        if ($json === null) {
            return null;
        }
        try {
            $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!$value instanceof \stdClass) {
            return null;
        }

        $result = [];
        foreach (get_object_vars($value) as $name => $member) {
            $result[(string) $name] = $member instanceof \stdClass ? (array) $member : $member;
        }

        return $result;
    }

    /**
     * Strict base64url without padding (RFC 7515).
     */
    private static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
