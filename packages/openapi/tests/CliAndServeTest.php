<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\OpenApi\ServeDocument;

#[CoversClass(ServeDocument::class)]
final class CliAndServeTest extends TestCase
{
    private const string BIN = __DIR__ . '/../bin/shirakaba-openapi';

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function execute(array $arguments): array
    {
        $command = [PHP_BINARY, self::BIN, ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = isset($pipes[1]) ? stream_get_contents($pipes[1]) : false;
        $stderr = isset($pipes[2]) ? stream_get_contents($pipes[2]) : false;

        return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
    }

    public function testWritesTheDocumentAndWarnings(): void
    {
        [$status, $stdout, $stderr] = self::execute([__DIR__ . '/Fixtures/app.php', '--config=' . __DIR__ . '/Fixtures/openapi.php']);

        self::assertSame(0, $status);
        self::assertStringContainsString('"title": "Tasks"', $stdout);
        self::assertStringContainsString('warning: Route "GET /opaque"', $stderr);
    }

    public function testStrictModeFailsOnWarnings(): void
    {
        [$status] = self::execute([__DIR__ . '/Fixtures/app.php', '--strict']);

        self::assertSame(1, $status);
    }

    public function testWritesToAFile(): void
    {
        $file = sys_get_temp_dir() . '/shirakaba-openapi-' . bin2hex(random_bytes(6)) . '.json';
        try {
            [$status, $stdout] = self::execute([__DIR__ . '/Fixtures/app.php', '--output=' . $file]);

            self::assertSame(0, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('"openapi": "3.1.1"', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testPrintsHelp(): void
    {
        [$status, $stdout] = self::execute(['--help']);

        self::assertSame(0, $status);
        self::assertStringStartsWith('Usage: shirakaba-openapi', $stdout);
    }

    public function testRejectsBadUsage(): void
    {
        self::assertSame(2, self::execute([])[0]);
        self::assertSame(2, self::execute([__DIR__ . '/Fixtures/openapi.php'])[0]);
    }

    public function testServesThePregeneratedDocument(): void
    {
        $file = sys_get_temp_dir() . '/shirakaba-openapi-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, '{"openapi":"3.1.1"}');
        try {
            $app = new AppBuilder();
            $app->middleware(new ServeDocument($file));
            $app->get('/', static fn (): Text => new Text('home'));
            $client = new TestClient($app->build());

            $response = $client->get('/openapi.json');
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame('{"openapi":"3.1.1"}', (string) $response->getBody());
            self::assertSame('', (string) $client->head('/openapi.json')->getBody());
            self::assertSame('home', (string) $client->get('/')->getBody());
            // Other methods fall through to the router, where no route matches.
            self::assertSame(404, $client->post('/openapi.json')->getStatusCode());
        } finally {
            unlink($file);
        }
    }

    public function testRejectsAMissingDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServeDocument('/nonexistent/openapi.json');
    }
}
