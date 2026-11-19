<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\Http\Message\HttpFactory;
use Shirakaba\HttpClient\HttpClient;
use Shirakaba\HttpClient\HttpClientOptions;
use Shirakaba\PubSub\MercurePublisher;
use Shirakaba\PubSub\MercureTokens;

/**
 * Publishes to and subscribes from the Mercure 1.0 hub built into FrankenPHP, started with
 * tests/Fixtures/Caddyfile, when FRANKENPHP_BINARY is set (design/11-real-time-events.md).
 */
#[CoversClass(MercurePublisher::class)]
#[CoversClass(MercureTokens::class)]
final class MercureHubTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';
    private const string ISSUER = 'https://shirakaba.test';
    private const string PREFIX = 'https://shirakaba.test/streams/';

    /** @var resource|null */
    private static $hub = null;
    private static int $port = 0;
    private static string $directory = '';

    public static function setUpBeforeClass(): void
    {
        $binary = getenv('FRANKENPHP_BINARY');
        if (!is_string($binary) || $binary === '' || !is_executable($binary)) {
            return;
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::$directory = sys_get_temp_dir() . '/shirakaba-mercure-' . bin2hex(random_bytes(4));
        mkdir(self::$directory);
        $hub = proc_open(
            [$binary, 'run', '--config', __DIR__ . '/Fixtures/Caddyfile', '--adapter', 'caddyfile'],
            [
                ['pipe', 'r'],
                ['file', '/dev/null', 'w'],
                ['file', '/dev/null', 'w'],
            ],
            $pipes,
            self::$directory,
            ['HUB_DIR' => self::$directory, 'HUB_PORT' => (string) self::$port, 'HUB_KEY' => self::KEY, 'HOME' => self::$directory],
        );
        self::assertIsResource($hub);
        self::$hub = $hub;
        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }
        self::fail('The Mercure hub did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$hub !== null) {
            proc_terminate(self::$hub);
            proc_close(self::$hub);
            self::$hub = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$hub === null) {
            self::markTestSkipped('Set FRANKENPHP_BINARY to run against the Mercure hub built into FrankenPHP.');
        }
    }

    private static function hubUrl(): string
    {
        return sprintf('http://127.0.0.1:%d/.well-known/mercure', self::$port);
    }

    private static function tokens(): MercureTokens
    {
        return new MercureTokens(self::ISSUER, self::hubUrl(), self::KEY);
    }

    private static function publisher(MercureTokens|string $token, ?AbstractLogger $logger = null): MercurePublisher
    {
        $factory = new HttpFactory();
        $client = new HttpClient(new HttpClientOptions(timeout: 5.0, allowAddress: static fn (string $address): bool => $address === '127.0.0.1'));

        return new MercurePublisher($client, $factory, $factory, self::hubUrl(), $token, self::PREFIX, logger: $logger);
    }

    private static function writer(): Writer
    {
        return new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer();
    }

    /**
     * Opens a subscription and returns the socket once the hub has answered.
     *
     * @return resource
     */
    private static function subscribe(string $topic, ?string $token = null)
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, timeout: 5);
        self::assertNotFalse($socket);
        $request = sprintf(
            "GET /.well-known/mercure?match=%s HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nAccept: text/event-stream\r\n",
            rawurlencode($topic),
            self::$port,
        );
        if ($token !== null) {
            $request .= 'Authorization: Bearer ' . $token . "\r\n";
        }
        fwrite($socket, $request . "\r\n");
        stream_set_timeout($socket, 5);
        $status = (string) fgets($socket);
        self::assertStringContainsString(' 200 ', $status, 'The hub accepted the subscription.');
        while (($line = fgets($socket)) !== false && trim($line) !== '') {
        }

        return $socket;
    }

    /**
     * The data of the next event on the subscription, or null within $seconds.
     *
     * @param resource $socket
     */
    private static function nextData($socket, float $seconds = 3.0): ?string
    {
        stream_set_timeout($socket, (int) ceil($seconds));
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $line = fgets($socket);
            if ($line === false) {
                return null;
            }
            if (str_starts_with($line, 'data: ')) {
                return rtrim(substr($line, 6), "\r\n");
            }
        }

        return null;
    }

    public function testPublishesPublicUpdatesWithIssuedTokens(): void
    {
        $subscription = self::subscribe(self::PREFIX . 'notes');
        self::publisher(self::tokens())->publish(self::writer(), 'notes', '{"id":1}');

        self::assertSame('{"id":1}', self::nextData($subscription));
    }

    public function testPrivateUpdatesReachOnlySubscribersWithAToken(): void
    {
        $topic = self::PREFIX . 'users_42';
        $authorised = self::subscribe($topic, self::tokens()->subscriber('user-42', [$topic]));
        $anonymous = self::subscribe($topic);
        self::publisher(self::tokens())->publishTo(self::writer(), ['users_42'], 'secret', private: true);

        self::assertSame('secret', self::nextData($authorised));
        self::assertNull(self::nextData($anonymous, 1.0));
    }

    public function testOneUpdateReachesSeveralTopics(): void
    {
        $second = self::subscribe(self::PREFIX . 'users_2');
        self::publisher(self::tokens())->publishTo(self::writer(), ['users_1', 'users_2', 'users_3'], 'hello');

        self::assertSame('hello', self::nextData($second));
    }

    public function testRefusedTokensRaiseOrAreLogged(): void
    {
        $narrow = self::tokens()->publisher([self::PREFIX . 'other']);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        self::publisher($narrow, $logger)->publish(self::writer(), 'notes', 'x');
        self::assertSame(['The Mercure hub refused the update with status 403.'], $logger->messages);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status 403');
        self::publisher($narrow)->publish(self::writer(), 'notes', 'x');
    }
}
