<?php

declare(strict_types=1);

namespace Shirakaba\Redis;

use InvalidArgumentException;

/**
 * A Redis client for the commands Shirakaba needs (design/10-background-work.md): RESP2 over a socket, TLS with
 * verified certificates, AUTH, a database number, timeouts and bounded replies.
 *
 *     $redis = RedisClient::fromUrl((string) getenv('REDIS_URL'));   // redis://, rediss://user:pass@host:6379/0
 *     $redis->command('SET', 'key', 'value', 'EX', 60);
 *
 * It connects on first use and keeps the connection for later commands; after an error that
 * may leave a reply unread it reconnects. Commands that leave state on the connection
 * (MULTI, WATCH, SUBSCRIBE, SELECT) are refused, so nothing reaches the next request in a
 * worker runtime.
 */
final class RedisClient
{
    /** @var list<string> */
    private const array STATEFUL = [
        'MULTI',
        'EXEC',
        'DISCARD',
        'WATCH',
        'UNWATCH',
        'SUBSCRIBE',
        'PSUBSCRIBE',
        'SSUBSCRIBE',
        'MONITOR',
        'SELECT',
        'CLIENT',
        'RESET',
        'QUIT',
        'HELLO',
    ];
    private const int MAX_BULK = 64 * 1024 * 1024;
    private const int MAX_ELEMENTS = 1_000_000;

    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        #[\SensitiveParameter] private readonly ?string $password = null,
        private readonly ?string $username = null,
        private readonly int $database = 0,
        private readonly bool $tls = false,
        private readonly float $timeout = 5.0,
    ) {}

    /**
     * Reads `redis://[user:password@]host[:port][/db]` or `rediss://...` (TLS).
     *
     * @throws InvalidArgumentException for another URL
     */
    public static function fromUrl(#[\SensitiveParameter] string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['redis', 'rediss'], true)) {
            throw new InvalidArgumentException('A Redis URL looks like redis://user:password@host:6379/0 or rediss://... for TLS.');
        }
        $database = trim($parts['path'] ?? '', '/');
        if ($database !== '' && !ctype_digit($database)) {
            throw new InvalidArgumentException('The Redis database is a number, such as /0.');
        }
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
        if ($pass === null && $user !== null) {
            // redis://password@host is the old form with only a password.
            [$user, $pass] = [null, $user];
        }

        return new self(
            $parts['host'],
            $parts['port'] ?? 6379,
            $pass,
            $user === '' ? null : $user,
            (int) ($database === '' ? 0 : $database),
            $parts['scheme'] === 'rediss',
        );
    }

    /**
     * Sends one command and returns its reply: a string, an integer, null, or a list of those.
     *
     * @throws RedisError when Redis cannot be reached or answers with an error
     * @throws InvalidArgumentException for a command that would leave state on the connection
     */
    public function command(string|int|float ...$arguments): mixed
    {
        $name = strtoupper((string) ($arguments[0] ?? ''));
        if ($name === '' || in_array($name, self::STATEFUL, true)) {
            throw new InvalidArgumentException(sprintf('"%s" cannot be sent: it is empty or leaves state on a shared connection.', $name));
        }

        return $this->send(array_values($arguments));
    }

    /**
     * Runs a Lua script atomically.
     *
     * @param list<string> $keys
     * @param list<string|int|float> $arguments
     * @throws RedisError
     */
    public function eval(string $script, array $keys = [], array $arguments = []): mixed
    {
        return $this->send(['EVAL', $script, count($keys), ...$keys, ...$arguments]);
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * @param list<string|int|float> $arguments
     * @throws RedisError
     */
    private function send(array $arguments): mixed
    {
        $stream = $this->connection();
        try {
            $this->write($stream, self::encode($arguments));

            return $this->read($stream);
        } catch (RedisError $e) {
            if ($e->getCode() !== 1) {
                // The connection may hold half a reply; start again next time.
                $this->close();
            }

            throw $e;
        }
    }

    /**
     * @return resource
     * @throws RedisError
     */
    private function connection()
    {
        if ($this->stream !== null) {
            return $this->stream;
        }
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client(
            ($this->tls ? 'tls://' : 'tcp://') . $this->host . ':' . $this->port,
            $code,
            $message,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            throw new RedisError(sprintf('Unable to connect to Redis at %s:%d: %s', $this->host, $this->port, $message));
        }
        stream_set_timeout($stream, (int) $this->timeout, (int) (($this->timeout - floor($this->timeout)) * 1e6));
        $this->stream = $stream;
        try {
            if ($this->password !== null) {
                $this->write($stream, self::encode($this->username === null ? ['AUTH', $this->password] : ['AUTH', $this->username, $this->password]));
                $this->read($stream);
            }
            if ($this->database !== 0) {
                $this->write($stream, self::encode(['SELECT', $this->database]));
                $this->read($stream);
            }
        } catch (RedisError $e) {
            $this->close();

            throw new RedisError('Redis refused the connection: ' . $e->getMessage(), 0, $e);
        }

        return $stream;
    }

    /**
     * @param list<string|int|float> $arguments
     */
    private static function encode(array $arguments): string
    {
        $out = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $value = (string) $argument;
            $out .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
        }

        return $out;
    }

    /**
     * @param resource $stream
     * @throws RedisError
     */
    private function write($stream, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new RedisError('Writing to Redis failed.');
            }
            $data = substr($data, $written);
        }
    }

    /**
     * @param resource $stream
     * @throws RedisError
     */
    private function read($stream): mixed
    {
        $line = $this->line($stream);
        $prefix = $line[0] ?? '';
        $rest = substr($line, 1);

        return match ($prefix) {
            '+' => $rest,
            '-' => throw new RedisError('Redis answered: ' . $rest, 1),
            ':' => (int) $rest,
            '$' => $this->bulk($stream, (int) $rest),
            '*' => $this->array($stream, (int) $rest),
            default => throw new RedisError('Redis sent a reply that is not RESP.'),
        };
    }

    /**
     * @param resource $stream
     * @throws RedisError
     */
    private function bulk($stream, int $length): ?string
    {
        if ($length === -1) {
            return null;
        }
        if ($length < 0 || $length > self::MAX_BULK) {
            throw new RedisError('Redis sent a reply that is too large.');
        }
        $data = '';
        while (strlen($data) < ($length + 2)) {
            $chunk = @fread($stream, max(1, $length + 2 - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new RedisError($this->timedOut($stream) ? 'Redis did not answer in time.' : 'Redis closed the connection.');
            }
            $data .= $chunk;
        }

        return substr($data, 0, $length);
    }

    /**
     * @param resource $stream
     * @return list<mixed>|null
     * @throws RedisError
     */
    private function array($stream, int $count): ?array
    {
        if ($count === -1) {
            return null;
        }
        if ($count < 0 || $count > self::MAX_ELEMENTS) {
            throw new RedisError('Redis sent a reply with too many elements.');
        }
        $items = [];
        $error = null;
        for ($i = 0; $i < $count; $i++) {
            try {
                $items[] = $this->read($stream);
            } catch (RedisError $e) {
                if ($e->getCode() !== 1) {
                    throw $e;
                }
                // An error inside a list (from a script) is read to the end first.
                $error ??= $e;
            }
        }
        if ($error !== null) {
            throw $error;
        }

        return $items;
    }

    /**
     * @param resource $stream
     * @throws RedisError
     */
    private function line($stream): string
    {
        $line = @fgets($stream, 65536);
        if ($line === false) {
            throw new RedisError($this->timedOut($stream) ? 'Redis did not answer in time.' : 'Redis closed the connection.');
        }
        if (!str_ends_with($line, "\r\n")) {
            throw new RedisError('Redis sent a reply line that is too long or cut off.');
        }

        return substr($line, 0, -2);
    }

    /**
     * @param resource $stream
     */
    private function timedOut($stream): bool
    {
        return stream_get_meta_data($stream)['timed_out'];
    }
}
