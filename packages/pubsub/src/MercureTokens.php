<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Shirakaba\Time\SystemClock;

/**
 * Issues Mercure 1.0 access tokens (design/11-real-time-events.md): JWT access tokens (RFC 9068, header
 * `typ: at+jwt`) signed with HS256, carrying `iss`, `aud`, `sub`, `client_id`, `iat`, `exp`,
 * `jti` and an `authorization_details` entry of type
 * `https://mercure.rocks/authorization-detail` that grants `publish` or `subscribe` on topics.
 *
 *     $tokens = new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', $secret);
 *     $publisher = $tokens->publisher();                                  // any topic
 *     $subscriber = $tokens->subscriber('user-42', ['https://example.com/streams/users/42']);
 *     $response->withAddedHeader('Set-Cookie', $tokens->cookie($subscriber));
 *
 * The issuer must be one the hub trusts (`issuer` in its Caddyfile) with this secret as the
 * publisher or subscriber key, and the audience its `resource_identifier`.
 */
final readonly class MercureTokens
{
    public const string DETAIL_TYPE = 'https://mercure.rocks/authorization-detail';
    public const string COOKIE = '__Secure-mercure_access_token';

    /**
     * @param string $issuer the `iss` the hub trusts
     * @param string $audience the hub's resource identifier, usually its URL
     * @param string $secret the HS256 key, at least 32 bytes
     * @param int $lifetime seconds until tokens expire
     * @throws InvalidArgumentException for a short secret or an empty issuer or audience
     */
    public function __construct(
        private string $issuer,
        private string $audience,
        #[\SensitiveParameter] private string $secret,
        private int $lifetime = 3600,
        private ClockInterface $clock = new SystemClock(),
        private string $clientId = 'shirakaba',
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('A Mercure HS256 secret must be at least 32 bytes.');
        }
        if ($issuer === '' || $audience === '' || $lifetime < 1) {
            throw new InvalidArgumentException('Mercure tokens need an issuer, an audience and a positive lifetime.');
        }
    }

    /**
     * A token for publishing to the topics; "*" (the default) matches every topic, which a
     * hub requires for public updates to arbitrary topics.
     *
     * @param list<string> $topics exact topics, or "*"
     * @param list<string> $patterns URL Pattern matchers such as "https://example.com/users/:id"
     */
    public function publisher(array $topics = ['*'], array $patterns = [], string $subject = 'publisher'): string
    {
        return $this->token($subject, 'publish', $topics, $patterns);
    }

    /**
     * A token for receiving private updates on the topics, for one subscriber.
     *
     * @param list<string> $topics exact topics, or "*"
     * @param list<string> $patterns URL Pattern matchers
     */
    public function subscriber(string $subject, array $topics, array $patterns = []): string
    {
        return $this->token($subject, 'subscribe', $topics, $patterns);
    }

    /**
     * A subscriber token for #[EventChannel] instances (design/11-real-time-events.md): their topics are built exactly
     * as MercureEvents publishes them, after the same $topicPrefix as the publisher's.
     */
    public function channelSubscriber(string $subject, string $topicPrefix, object ...$channels): string
    {
        return $this->subscriber(
            $subject,
            array_values(array_map(static fn (object $channel): string => $topicPrefix . EventChannels::topic($channel), $channels)),
        );
    }

    /**
     * A Set-Cookie value carrying a subscriber token the way hubs read it: the
     * `__Secure-mercure_access_token` cookie, Secure, HttpOnly, SameSite=Strict, scoped to the
     * hub's path. EventSource sends it with same-site requests; there is no query parameter.
     */
    public function cookie(string $token, string $path = '/.well-known/mercure', ?string $domain = null): string
    {
        return sprintf(
            '%s=%s; Path=%s;%s Max-Age=%d; Secure; HttpOnly; SameSite=Strict',
            self::COOKIE,
            $token,
            $path,
            $domain === null ? '' : ' Domain=' . $domain . ';',
            $this->lifetime,
        );
    }

    /**
     * @param 'publish'|'subscribe' $action
     * @param list<string> $topics
     * @param list<string> $patterns
     */
    private function token(string $subject, string $action, array $topics, array $patterns): string
    {
        if ($topics === [] && $patterns === []) {
            throw new InvalidArgumentException('A Mercure token needs at least one topic.');
        }
        $now = $this->clock->now()->getTimestamp();
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $subject,
            'client_id' => $this->clientId,
            'iat' => $now,
            'exp' => $now + $this->lifetime,
            'jti' => bin2hex(random_bytes(16)),
            'authorization_details' => [[
                'type' => self::DETAIL_TYPE,
                'actions' => [$action],
                'topics' => [
                    ...array_map(static fn (string $topic): array => ['match' => $topic], $topics),
                    ...array_map(static fn (string $pattern): array => ['match' => $pattern, 'match_type' => 'urlpattern'], $patterns),
                ],
            ]],
        ];
        $signed = self::encode(['alg' => 'HS256', 'typ' => 'at+jwt']) . '.' . self::encode($claims);

        return $signed . '.' . self::base64Url(hash_hmac('sha256', $signed, $this->secret, true));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return self::base64Url(self::json($data));
    }

    /**
     * @param array<string, mixed> $data
     * @throws \JsonException
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
