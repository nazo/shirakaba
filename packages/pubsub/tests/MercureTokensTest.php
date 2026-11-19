<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\PubSub\MercureTokens;
use Shirakaba\Time\FrozenClock;

#[CoversClass(MercureTokens::class)]
final class MercureTokensTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private static function decode(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        [$header, $claims, $signature] = $parts;
        self::assertSame(
            rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $claims, self::KEY, true)), '+/', '-_'), '='),
            $signature,
            'Signed with HS256 over header and claims.',
        );
        $decoded = [];
        foreach ([$header, $claims] as $part) {
            $json = json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($json);
            /** @var array<string, mixed> $json */
            $decoded[] = $json;
        }

        return [$decoded[0], $decoded[1]];
    }

    public function testIssuesMercure10AccessTokens(): void
    {
        $tokens = new MercureTokens(
            'https://example.com',
            'https://hub.example.com/.well-known/mercure',
            self::KEY,
            600,
            new FrozenClock('2026-11-12T00:00:00Z'),
        );

        [$header, $claims] = self::decode($tokens->subscriber('user-42', ['https://example.com/users/42'], ['https://example.com/rooms/:id']));

        self::assertSame(['alg' => 'HS256', 'typ' => 'at+jwt'], $header);
        self::assertSame('https://example.com', $claims['iss'] ?? null);
        self::assertSame('https://hub.example.com/.well-known/mercure', $claims['aud'] ?? null);
        self::assertSame('user-42', $claims['sub'] ?? null);
        self::assertSame(1794441600, $claims['iat'] ?? null);
        self::assertSame(1794442200, $claims['exp'] ?? null);
        self::assertIsString($claims['jti'] ?? null);
        self::assertSame(
            [[
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe'],
                'topics' => [
                    ['match' => 'https://example.com/users/42'],
                    ['match' => 'https://example.com/rooms/:id', 'match_type' => 'urlpattern'],
                ],
            ]],
            $claims['authorization_details'] ?? null,
        );

        [, $publisher] = self::decode($tokens->publisher());
        self::assertSame(
            [['type' => MercureTokens::DETAIL_TYPE, 'actions' => ['publish'], 'topics' => [['match' => '*']]]],
            $publisher['authorization_details'] ?? null,
        );
        self::assertArrayNotHasKey('mercure', $publisher, 'No legacy claim.');
    }

    public function testCookie(): void
    {
        $tokens = new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', self::KEY, 600);

        self::assertSame(
            '__Secure-mercure_access_token=abc; Path=/.well-known/mercure; Max-Age=600; Secure; HttpOnly; SameSite=Strict',
            $tokens->cookie('abc'),
        );
        self::assertSame('__Secure-mercure_access_token=abc; Path=/hub; Domain=example.com; Max-Age=600; Secure; HttpOnly; SameSite=Strict', $tokens->cookie(
            'abc',
            '/hub',
            'example.com',
        ));
    }

    public function testRejectsShortSecretsAndEmptyGrants(): void
    {
        try {
            new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', 'short');
            self::fail('A short secret is refused.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', self::KEY)->subscriber('u', []);
    }
}
