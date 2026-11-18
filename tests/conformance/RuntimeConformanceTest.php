<?php

declare(strict_types=1);

namespace Shirakaba\Conformance\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Drives real server processes with HTTP requests. Every runtime runs the same
 * application (app/index.php) and must give the same answers.
 */
#[CoversNothing]
final class RuntimeConformanceTest extends TestCase
{
    /** @var array<string, array{resource, int}> */
    private static array $servers = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as [$process]) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$servers = [];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runtimes(): iterable
    {
        yield 'php-fpm (built-in server)' => ['fpm'];
        yield 'frankenphp (worker mode)' => ['frankenphp'];
        yield 'roadrunner' => ['roadrunner'];
        yield 'swoole' => ['swoole'];
    }

    private static function port(string $runtime): int
    {
        if (isset(self::$servers[$runtime])) {
            return self::$servers[$runtime][1];
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new RuntimeException('No free port.');
        }
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $app = __DIR__ . '/app/index.php';
        $command = match ($runtime) {
            'fpm' => [PHP_BINARY, '-S', '127.0.0.1:' . $port, $app],
            'frankenphp' => self::frankenphp($app, $port),
            'roadrunner' => self::roadrunner($app, $port),
            'swoole' => [self::swoolePhp(), $app],
            default => throw new RuntimeException('Unknown runtime ' . $runtime),
        };
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__ . '/app', [...getenv(), 'RUNTIME' => $runtime, 'SWOOLE_PORT' => (string) $port]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ' . $runtime);
        }
        self::$servers[$runtime] = [$process, $port];

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, timeout: 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
            usleep(50_000);
        }

        throw new RuntimeException($runtime . ' did not start.');
    }

    /**
     * @return list<string>
     */
    private static function frankenphp(string $app, int $port): array
    {
        $configured = getenv('FRANKENPHP_BINARY');
        $binary = is_string($configured) && $configured !== '' ? $configured : trim((string) shell_exec('command -v frankenphp'));
        if ($binary === '') {
            self::markTestSkipped('FrankenPHP is not installed; put it on the PATH or set FRANKENPHP_BINARY to run the worker-mode conformance tests.');
        }

        return [$binary, 'php-server', '--listen', '127.0.0.1:' . $port, '--worker', $app];
    }

    /**
     * @return list<string>
     */
    private static function roadrunner(string $app, int $port): array
    {
        $configured = getenv('RR_BINARY');
        $binary = is_string($configured) && $configured !== '' ? $configured : trim((string) shell_exec('command -v rr'));
        if ($binary === '') {
            self::markTestSkipped('RoadRunner is not installed; put rr on the PATH or set RR_BINARY to run the RoadRunner conformance tests.');
        }

        // One worker, so the request counter of /process sees every request.
        $config = sys_get_temp_dir() . '/shirakaba-conformance-rr-' . $port . '.yaml';
        file_put_contents($config, json_encode([
            'version' => '3',
            'server' => ['command' => PHP_BINARY . ' ' . $app, 'env' => [['RUNTIME' => 'roadrunner']]],
            'http' => ['address' => '127.0.0.1:' . $port, 'pool' => ['num_workers' => 1]],
            'logs' => ['level' => 'error'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [$binary, 'serve', '-c', $config, '-w', dirname($app)];
    }

    private static function swoolePhp(): string
    {
        if (extension_loaded('swoole')) {
            return PHP_BINARY;
        }
        $configured = getenv('SWOOLE_PHP_BINARY');
        if (!is_string($configured) || $configured === '') {
            self::markTestSkipped('Swoole is not loaded; enable the extension or set SWOOLE_PHP_BINARY to a PHP binary that has it to run the Swoole conformance tests.');
        }

        return $configured;
    }

    /**
     * Sends a raw HTTP/1.1 request; http:// streams are disabled in tests (allow_url_fopen=0).
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function send(string $runtime, string $request): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::port($runtime), timeout: 5);
        if ($socket === false) {
            throw new RuntimeException('Connection failed.');
        }
        fwrite($socket, $request);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        $separator = strpos($raw, "\r\n\r\n");
        $head = $separator === false ? $raw : substr($raw, 0, $separator);
        $body = $separator === false ? '' : substr($raw, $separator + 4);
        $lines = explode("\r\n", $head);
        $status = (int) (explode(' ', $lines[0] ?? '')[1] ?? 0);
        $headers = array_slice($lines, 1);
        if (in_array('Transfer-Encoding: chunked', $headers, true)) {
            $body = self::unchunk($body);
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private static function unchunk(string $body): string
    {
        $decoded = '';
        while (($line = strpos($body, "\r\n")) !== false) {
            $size = hexdec(substr($body, 0, $line));
            if (!is_int($size) || $size === 0) {
                break;
            }
            $decoded .= substr($body, $line + 2, $size);
            $body = substr($body, $line + 2 + $size + 2);
        }

        return $decoded;
    }

    private static function get(string $path, string $host = 'localhost', string $method = 'GET'): string
    {
        return "{$method} {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
    }

    #[DataProvider('runtimes')]
    public function testHandlesARequest(string $runtime): void
    {
        $response = self::send($runtime, self::get('/hello?name=shirakaba'));

        self::assertSame(200, $response['status']);
        self::assertSame('hello shirakaba', $response['body']);
        self::assertContains('Content-Type: text/plain; charset=utf-8', $response['headers']);
    }

    #[DataProvider('runtimes')]
    public function testKeepsRepeatedSetCookieHeaders(string $runtime): void
    {
        $headers = self::send($runtime, self::get('/cookies'))['headers'];

        self::assertContains('Set-Cookie: a=1; Path=/', $headers);
        self::assertContains('Set-Cookie: b=2; Path=/', $headers);
    }

    #[DataProvider('runtimes')]
    public function testHeadHasNoBody(string $runtime): void
    {
        $response = self::send($runtime, self::get('/hello', method: 'HEAD'));

        self::assertSame(200, $response['status']);
        self::assertSame('', $response['body']);
    }

    #[DataProvider('runtimes')]
    public function testRejectsAnInvalidHostHeader(string $runtime): void
    {
        self::assertSame(400, self::send($runtime, self::get('/hello', 'evil.example/path'))['status']);
    }

    #[DataProvider('runtimes')]
    public function testReadsRequestBodies(string $runtime): void
    {
        $body = '{"x":1}';
        $response = self::send($runtime, "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);

        self::assertSame($body, $response['body']);
    }

    #[DataProvider('runtimes')]
    public function testReceivesUploads(string $runtime): void
    {
        $boundary = 'shirakaba-boundary';
        $payload = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\nhello upload\r\n--{$boundary}--\r\n";
        $response = self::send($runtime, "POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload);

        self::assertSame('a.txt 12', $response['body']);
    }

    #[DataProvider('runtimes')]
    public function testStreamsBodies(string $runtime): void
    {
        self::assertSame("one\ntwo\nthree\n", self::send($runtime, self::get('/stream'))['body']);
    }

    #[DataProvider('runtimes')]
    public function testStopsStreamingWhenTheClientDisconnects(string $runtime): void
    {
        $token = bin2hex(random_bytes(8));
        $marker = sys_get_temp_dir() . '/shirakaba-disconnect-' . $token;
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::port($runtime), timeout: 5);
        self::assertNotFalse($socket);
        fwrite($socket, self::get('/endless?token=' . $token));
        self::assertNotSame('', (string) fread($socket, 4096), 'The stream started.');
        fclose($socket);

        // 500 chunks take five seconds; a detected disconnect ends far sooner.
        $deadline = microtime(true) + 3.0;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        try {
            self::assertFileExists($marker, 'The request cleanup ran after the disconnect.');
            self::assertSame('cancelled', file_get_contents($marker));
        } finally {
            @unlink($marker);
        }
    }

    #[DataProvider('runtimes')]
    public function testRunsInTheExpectedMode(string $runtime): void
    {
        $first = self::send($runtime, self::get('/process'))['body'];
        $second = self::send($runtime, self::get('/process'))['body'];

        if ($runtime !== 'fpm') {
            // The same worker kept the application, so its request counter grew.
            self::assertMatchesRegularExpression('/^\d+ worker$/', $second);
            self::assertGreaterThan((int) $first, (int) $second);
        } else {
            self::assertSame(['1 classic', '1 classic'], [$first, $second]);
        }
    }

    #[DataProvider('runtimes')]
    public function testDoesNotLeakStateBetweenRequests(string $runtime): void
    {
        foreach (range(1, 5) as $_) {
            self::assertSame('clean', self::send($runtime, self::get('/scope'))['body']);
        }
    }

    #[DataProvider('runtimes')]
    public function testDoesNotLeakDatabaseStateBetweenRequests(string $runtime): void
    {
        foreach (range(1, 5) as $_) {
            self::assertSame('clean', self::send($runtime, self::get('/database', method: 'POST'))['body']);
        }
    }
}
