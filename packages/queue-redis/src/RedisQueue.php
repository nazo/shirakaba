<?php

declare(strict_types=1);

namespace Shirakaba\QueueRedis;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Shirakaba\Database\Writer;
use Shirakaba\Queue\PushOptions;
use Shirakaba\Queue\Queue;
use Shirakaba\Queue\ReservedJob;
use Shirakaba\Queue\TraceContext;
use Shirakaba\Redis\RedisClient;
use Shirakaba\Redis\RedisError;
use Shirakaba\Time\SystemClock;

/**
 * Jobs in Redis (design/10-background-work.md), for applications that keep the queue's traffic away from
 * the database. Every change is one Lua script, so concurrent workers never take the same job;
 * reservations carry a token and a lease, as in DatabaseQueue.
 *
 * Pushing happens after the caller's transaction commits (a rolled-back transaction pushes
 * nothing). Unlike DatabaseQueue, a process that dies between the commit and the push loses
 * the job, and push() cannot know yet whether a job with the same unique key is waiting: it
 * returns true and the duplicate is dropped when it reaches Redis. Use DatabaseQueue where a
 * job must never be lost.
 */
final readonly class RedisQueue implements Queue
{
    private const string PUSH = <<<'LUA'
        if ARGV[7] ~= '' then
            if redis.call('SET', KEYS[3], ARGV[1], 'NX') == false then return 0 end
        end
        redis.call('HSET', KEYS[1], 'queue', ARGV[2], 'type', ARGV[3], 'payload', ARGV[4], 'attempts', 0, 'max', ARGV[5], 'unique', ARGV[7], 'trace', ARGV[8], 'reservation', '')
        redis.call('ZADD', KEYS[2], ARGV[6], ARGV[1])
        return 1
        LUA;

    private const string RESERVE = <<<'LUA'
        local prefix, now, lease, token = ARGV[1], tonumber(ARGV[2]), tonumber(ARGV[3]), ARGV[4]
        local expired = redis.call('ZRANGEBYSCORE', prefix .. 'leased', '-inf', now)
        for _, id in ipairs(expired) do
            redis.call('ZREM', prefix .. 'leased', id)
            local queue = redis.call('HGET', prefix .. 'job:' .. id, 'queue')
            if queue then redis.call('ZADD', prefix .. 'due:' .. queue, now, id) end
        end
        local best, bestScore = nil, nil
        for i = 5, #ARGV do
            local found = redis.call('ZRANGEBYSCORE', prefix .. 'due:' .. ARGV[i], '-inf', now, 'WITHSCORES', 'LIMIT', 0, 1)
            if found[1] and (bestScore == nil or tonumber(found[2]) < bestScore) then
                best, bestScore = found[1], tonumber(found[2])
            end
        end
        if best == nil then return nil end
        local key = prefix .. 'job:' .. best
        local queue = redis.call('HGET', key, 'queue')
        redis.call('ZREM', prefix .. 'due:' .. queue, best)
        redis.call('ZADD', prefix .. 'leased', now + lease, best)
        redis.call('HINCRBY', key, 'attempts', 1)
        redis.call('HSET', key, 'reservation', token)
        local job = redis.call('HMGET', key, 'queue', 'type', 'payload', 'attempts', 'max', 'trace')
        return {best, job[1], job[2], job[3], job[4], job[5], job[6]}
        LUA;

    /** ARGV: prefix, id, token, action (ack, release, fail), time, error */
    private const string FINISH = <<<'LUA'
        local prefix, id, token, action, at = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5]
        local key = prefix .. 'job:' .. id
        if redis.call('HGET', key, 'reservation') ~= token then return 0 end
        if redis.call('ZREM', prefix .. 'leased', id) == 0 then return 0 end
        local unique = redis.call('HGET', key, 'unique')
        if action == 'release' then
            redis.call('HSET', key, 'reservation', '')
            redis.call('ZADD', prefix .. 'due:' .. redis.call('HGET', key, 'queue'), at, id)
            return 1
        end
        if unique and unique ~= '' then redis.call('DEL', prefix .. 'unique:' .. unique) end
        if action == 'ack' then
            redis.call('DEL', key)
        else
            redis.call('HSET', key, 'reservation', '', 'error', ARGV[6], 'unique', '')
            redis.call('ZADD', prefix .. 'failed', at, id)
        end
        return 1
        LUA;

    /**
     * @throws InvalidArgumentException for a prefix with whitespace
     */
    public function __construct(
        private RedisClient $redis,
        private ClockInterface $clock = new SystemClock(),
        private string $prefix = 'shirakaba:queue:',
    ) {
        if (preg_match('/[\s\0]/', $prefix) === 1) {
            throw new InvalidArgumentException('A queue key prefix cannot contain whitespace.');
        }
    }

    /**
     * Queues the job when the caller's transaction commits; see the class for what that means.
     */
    public function push(string $type, string $payload, Writer $db, PushOptions $options): bool
    {
        $now = $this->clock->now();
        $available = $options->availableAt ?? $now->modify(sprintf('+%d seconds', $options->delay));
        $id = bin2hex(random_bytes(16));
        $trace = TraceContext::current() ?? '';
        $db->afterCommit(function () use ($id, $type, $payload, $options, $available, $trace): void {
            $this->redis->eval(
                self::PUSH,
                [
                    $this->prefix . 'job:' . $id,
                    $this->prefix . 'due:' . $options->queue,
                    $this->prefix . 'unique:' . ($options->uniqueKey ?? ''),
                ],
                [$id, $options->queue, $type, $payload, $options->maxAttempts, self::milliseconds($available), $options->uniqueKey ?? '', $trace],
            );
        });

        return true;
    }

    /**
     * @throws RedisError
     * @param non-empty-list<string> $queues
     * @param positive-int $leaseSeconds
     */
    public function reserve(array $queues, int $leaseSeconds): ?ReservedJob
    {
        $token = bin2hex(random_bytes(16));
        $reply = $this->redis->eval(self::RESERVE, [], [$this->prefix, self::milliseconds($this->clock->now()), $leaseSeconds * 1000, $token, ...$queues]);
        if (!is_array($reply) || count($reply) < 7) {
            return null;
        }
        [$id, $queue, $type, $payload, $attempts, $max, $trace] = [
            self::at($reply, 0),
            self::at($reply, 1),
            self::at($reply, 2),
            self::at($reply, 3),
            self::at($reply, 4),
            self::at($reply, 5),
            self::at($reply, 6),
        ];

        return new ReservedJob($id, $queue, $type, $payload, max(1, (int) $attempts), max(1, (int) $max), $token, $trace === '' ? null : $trace);
    }

    public function acknowledge(ReservedJob $job): bool
    {
        return $this->finish($job, 'ack', $this->clock->now()->getTimestamp() * 1000);
    }

    public function release(ReservedJob $job, int $delaySeconds): bool
    {
        return $this->finish($job, 'release', self::milliseconds($this->clock->now()->modify(sprintf('+%d seconds', $delaySeconds))));
    }

    public function fail(ReservedJob $job, string $error): bool
    {
        return $this->finish($job, 'fail', self::milliseconds($this->clock->now()), substr($error, 0, 2000));
    }

    /**
     * Failed jobs, newest first.
     *
     * @return list<array{id: string, queue: string, type: string, attempts: int, error: string}>
     * @throws RedisError
     */
    public function failed(int $limit = 50): array
    {
        $ids = $this->redis->command('ZREVRANGE', $this->prefix . 'failed', 0, max(0, $limit - 1));
        $failed = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $fields = $this->redis->command('HMGET', $this->prefix . 'job:' . (string) (is_scalar($id) ? $id : ''), 'queue', 'type', 'attempts', 'error');
            if (!is_array($fields)) {
                continue;
            }
            [$queue, $type, $attempts, $error] = [self::at($fields, 0), self::at($fields, 1), self::at($fields, 2), self::at($fields, 3)];
            $failed[] = ['id' => is_scalar($id) ? (string) $id : '', 'queue' => $queue, 'type' => $type, 'attempts' => (int) $attempts, 'error' => $error];
        }

        return $failed;
    }

    /**
     * @throws RedisError
     */
    private function finish(ReservedJob $job, string $action, int $at, string $error = ''): bool
    {
        return $this->redis->eval(self::FINISH, [], [$this->prefix, $job->id, $job->reservation, $action, $at, $error]) === 1;
    }

    /**
     * A value of a reply as a string, empty when it is missing or not scalar.
     *
     * @param array<mixed> $values
     */
    private static function at(array $values, int $index): string
    {
        $value = $values[$index] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function milliseconds(\DateTimeImmutable $time): int
    {
        return (int) $time->format('Uv');
    }
}
