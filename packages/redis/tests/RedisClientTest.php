<?php

declare(strict_types=1);

namespace Shirakaba\Redis\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Redis\RedisClient;
use Shirakaba\Redis\RedisError;

#[CoversClass(RedisClient::class)]
#[CoversClass(RedisError::class)]
final class RedisClientTest extends TestCase
{
    public static function redis(): RedisClient
    {
        $url = getenv('SHIRAKABA_TEST_REDIS_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set SHIRAKABA_TEST_REDIS_URL (composer test:databases) to run against Redis.');
        }

        return RedisClient::fromUrl($url);
    }

    public function testRoundTripsCommandsAndScripts(): void
    {
        $redis = self::redis();
        $key = 'shirakaba:test:' . bin2hex(random_bytes(4));

        self::assertSame('OK', $redis->command('SET', $key, "白樺\r\n\0binary", 'EX', 60));
        self::assertSame("白樺\r\n\0binary", $redis->command('GET', $key));
        self::assertNull($redis->command('GET', $key . ':missing'));
        self::assertSame(1, $redis->command('DEL', $key));
        self::assertSame([1, 'two', null, [3]], $redis->eval("return {1, 'two', false, {3}}"));
        self::assertSame(2, $redis->eval('return redis.call("INCRBY", KEYS[1], ARGV[1])', [$key], [2]));
        $redis->command('DEL', $key);
    }

    public function testErrorsKeepTheConnectionUsable(): void
    {
        $redis = self::redis();
        try {
            $redis->command('NOSUCHCOMMAND');
            self::fail('An unknown command must fail.');
        } catch (RedisError $e) {
            self::assertStringContainsString('Redis answered: ERR', $e->getMessage());
        }

        self::assertSame('PONG', $redis->command('PING'));
    }

    public function testRefusesStatefulCommandsAndBadUrls(): void
    {
        foreach (['MULTI', 'subscribe', 'SELECT', ''] as $command) {
            try {
                new RedisClient()->command($command);
                self::fail(sprintf('"%s" must be refused.', $command));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        RedisClient::fromUrl('rediss://user:p%40ss@cache.example.com:6380/2');
        $this->expectException(InvalidArgumentException::class);
        RedisClient::fromUrl('http://example.com');
    }

    public function testUnreachableServersFail(): void
    {
        $this->expectException(RedisError::class);
        new RedisClient('127.0.0.1', 1, timeout: 0.5)->command('PING');
    }
}
