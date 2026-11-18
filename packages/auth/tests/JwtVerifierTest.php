<?php

declare(strict_types=1);

namespace Shirakaba\Auth\Tests;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Time\FrozenClock;
use Shirakaba\Auth\JwtKey;
use Shirakaba\Auth\JwtVerifier;
use Shirakaba\Auth\Tests\Fixtures\Signer;
use Shirakaba\Auth\Tests\Fixtures\User;

#[CoversClass(JwtVerifier::class)]
#[CoversClass(JwtKey::class)]
final class JwtVerifierTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const string SECRET = 'a-secret-that-is-long-enough-for-hs256!';

    private static ?OpenSSLAsymmetricKey $rsa = null;
    private static ?OpenSSLAsymmetricKey $ec = null;

    private static function rsa(): OpenSSLAsymmetricKey
    {
        return self::$rsa ??= self::generate(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }

    private static function ec(): OpenSSLAsymmetricKey
    {
        return self::$ec ??= self::generate(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    /**
     * @param array<string, int|string> $options
     */
    private static function generate(array $options): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new($options);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private static function publicPem(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key'] ?? null);

        return $details['key'];
    }

    /**
     * Verifies $token inside a scope of its own, as BearerAuthentication would in a request.
     */
    private static function verify(JwtVerifier $verifier, string $token): ?object
    {
        $principal = null;
        $error = ScopedWork::run([], 'TEST', 'verify', new Cancellation(), static function (RequestScope $scope) use ($verifier, $token, &$principal): void {
            $principal = $verifier->verify($token, $scope);
        });
        self::assertNull($error);

        return $principal;
    }

    private static function verifier(JwtKey ...$keys): JwtVerifier
    {
        return new JwtVerifier(
            array_values($keys) === [] ? [JwtKey::hmac(self::SECRET)] : array_values($keys),
            static fn (array $claims): ?User => is_string($claims['sub'] ?? null) ? new User($claims['sub']) : null,
            issuer: 'https://auth.example.com',
            audience: 'api',
            clock: new FrozenClock(new \DateTimeImmutable('@' . self::NOW)),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function claims(array $overrides = []): array
    {
        return [...['sub' => 'u1', 'iss' => 'https://auth.example.com', 'aud' => 'api', 'exp' => self::NOW + 300, 'iat' => self::NOW], ...$overrides];
    }

    public function testVerifiesEachAlgorithm(): void
    {
        $hs = Signer::token(['alg' => 'HS256', 'typ' => 'JWT'], self::claims(), self::SECRET);
        $rs = Signer::token(['alg' => 'RS256', 'kid' => 'r1'], self::claims(['aud' => ['web', 'api']]), self::rsa());
        $es = Signer::token(['alg' => 'ES256'], self::claims(), self::ec());

        self::assertEquals(new User('u1'), self::verify(self::verifier(), $hs));
        self::assertEquals(new User('u1'), self::verify(self::verifier(JwtKey::rsa(self::publicPem(self::rsa()), 'r1')), $rs));
        self::assertEquals(new User('u1'), self::verify(self::verifier(JwtKey::ec(self::publicPem(self::ec()))), $es));
    }

    public function testMapsClaimsToAPrincipalThroughTheRequestScope(): void
    {
        $verifier = new JwtVerifier(
            [JwtKey::hmac(self::SECRET)],
            static fn (array $claims, RequestScope $scope): ?User => $scope->find(User::class),
            clock: new FrozenClock(new \DateTimeImmutable('@' . self::NOW)),
        );
        $principal = null;
        ScopedWork::run([], 'TEST', 'verify', new Cancellation(), static function (RequestScope $scope) use ($verifier, &$principal): void {
            $scope->set(new User('from-scope'));
            $principal = $verifier->verify(Signer::token(['alg' => 'HS256'], self::claims(), self::SECRET), $scope);
        });

        self::assertEquals(new User('from-scope'), $principal);
    }

    /**
     * @return iterable<string, array{\Closure(): string}>
     */
    public static function invalidTokens(): iterable
    {
        $valid = static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(), self::SECRET);
        yield 'tampered claims' => [static function () use ($valid): string {
            $parts = explode('.', $valid());

            return ($parts[0] ?? '') . '.' . Signer::encode((string) json_encode(self::claims(['sub' => 'admin']))) . '.' . ($parts[2] ?? '');
        }];
        yield 'alg none' => [static fn (): string => Signer::encode('{"alg":"none"}') . '.' . Signer::encode((string) json_encode(self::claims())) . '.'];
        yield 'wrong algorithm for the key' => [static fn (): string => Signer::token(['alg' => 'HS512'], self::claims(), self::SECRET)];
        yield 'wrong secret' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(), str_repeat('x', 40))];
        yield 'expired' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['exp' => self::NOW - 61]), self::SECRET)];
        yield 'missing exp' => [static function (): string {
            $claims = self::claims();
            unset($claims['exp']);

            return Signer::token(['alg' => 'HS256'], $claims, self::SECRET);
        }];
        yield 'not yet valid' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['nbf' => self::NOW + 120]), self::SECRET)];
        yield 'issued in the future' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['iat' => self::NOW + 120]), self::SECRET)];
        yield 'wrong issuer' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['iss' => 'https://evil.example']), self::SECRET)];
        yield 'wrong audience' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['aud' => ['web']]), self::SECRET)];
        yield 'audience as an object' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['aud' => ['x' => 'api']]), self::SECRET)];
        yield 'string exp' => [static fn (): string => Signer::token(['alg' => 'HS256'], self::claims(['exp' => (string) (self::NOW + 300)]), self::SECRET)];
        yield 'crit header' => [static fn (): string => Signer::token(['alg' => 'HS256', 'crit' => ['exp']], self::claims(), self::SECRET)];
        yield 'wrong typ' => [static fn (): string => Signer::token(['alg' => 'HS256', 'typ' => 'at+jwt+other'], self::claims(), self::SECRET)];
        yield 'padded base64' => [static fn (): string => $valid() . '='];
        yield 'two parts' => [static fn (): string => 'abc.def'];
        yield 'header not an object' => [static fn (): string => Signer::encode('[]') . '.' . Signer::encode('{}') . '.' . Signer::encode('x')];
    }

    /**
     * @param \Closure(): string $token
     */
    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidTokens(\Closure $token): void
    {
        self::assertNull(self::verify(self::verifier(), $token()));
    }

    public function testRejectsAlgorithmConfusionWithAPublicKeyUsedAsHmacSecret(): void
    {
        $pem = self::publicPem(self::rsa());
        $forged = Signer::token(['alg' => 'HS256'], self::claims(), $pem);

        self::assertNull(self::verify(self::verifier(JwtKey::rsa($pem)), $forged));
    }

    public function testSelectsKeysById(): void
    {
        $other = self::generate(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $verifier = self::verifier(JwtKey::rsa(self::publicPem($other), 'old'), JwtKey::rsa(self::publicPem(self::rsa()), 'new'));

        self::assertNotNull(self::verify($verifier, Signer::token(['alg' => 'RS256', 'kid' => 'new'], self::claims(), self::rsa())));
        self::assertNull(self::verify($verifier, Signer::token(['alg' => 'RS256', 'kid' => 'old'], self::claims(), self::rsa())));
        self::assertNull(self::verify($verifier, Signer::token(['alg' => 'RS256'], self::claims(), self::rsa())), 'Keys with IDs need a matching kid.');
    }

    public function testRejectsWeakKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JwtKey::hmac('short');
    }

    public function testRejectsKeysOfTheWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JwtKey::ec(self::publicPem(self::rsa()));
    }
}
