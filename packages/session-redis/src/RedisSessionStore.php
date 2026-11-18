<?php

declare(strict_types=1);

namespace Shirakaba\SessionRedis;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Redis\RedisClient;
use Shirakaba\Redis\RedisError;
use Shirakaba\Session\JsonData;
use Shirakaba\Session\SessionStore;
use Shirakaba\Time\SystemClock;

/**
 * Keeps sessions in Redis, shared by every instance and removed by Redis when they
 * expire, so no scheduled job is needed:
 *
 *     $app->middleware(new SessionMiddleware(new RedisSessionStore(RedisClient::fromUrl($url))));
 *
 * Keys are the prefix and the SHA-256 hash of the session ID, never the ID itself; values are
 * JSON with the expiry, which is also checked on load, so the application's clock decides as
 * with the other stores. When Redis cannot be reached the request fails with a 503 instead of
 * starting an empty session.
 */
final readonly class RedisSessionStore implements SessionStore
{
    /**
     * @throws InvalidArgumentException for a prefix with spaces or control characters
     */
    public function __construct(
        private RedisClient $redis,
        private ClockInterface $clock = new SystemClock(),
        private string $prefix = 'shirakaba:session:',
    ) {
        if (preg_match('/[\s\0]/', $prefix) === 1) {
            throw new InvalidArgumentException('A session key prefix cannot contain whitespace.');
        }
    }

    /**
     * @return array<string, mixed>|null
     * @throws HttpFailure (503) when Redis cannot be reached
     */
    public function load(RequestScope $scope, string $id): ?array
    {
        $value = $this->call('GET', $this->key($id));
        $record = is_string($value) ? JsonData::decode($value) : null;
        $expires = $record['expires'] ?? null;
        $data = $record['data'] ?? null;
        if (!is_int($expires) || $expires <= $this->clock->now()->getTimestamp() || !is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @throws HttpFailure (503) when Redis cannot be reached
     */
    public function save(RequestScope $scope, string $id, array $data, int $lifetime): void
    {
        $value = JsonData::encode(['expires' => $this->clock->now()->getTimestamp() + $lifetime, 'data' => $data]);
        $this->call('SET', $this->key($id), $value, 'EX', max(1, $lifetime));
    }

    /**
     * @throws HttpFailure (503) when Redis cannot be reached
     */
    public function delete(RequestScope $scope, string $id): void
    {
        $this->call('DEL', $this->key($id));
    }

    private function key(string $id): string
    {
        return $this->prefix . hash('sha256', $id);
    }

    /**
     * @throws HttpFailure
     */
    private function call(string|int ...$arguments): mixed
    {
        try {
            return $this->redis->command(...$arguments);
        } catch (RedisError $e) {
            throw new HttpFailure(503, 'Sessions are unavailable; try again shortly.', previous: $e);
        }
    }
}
