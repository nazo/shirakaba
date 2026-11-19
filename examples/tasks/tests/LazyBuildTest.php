<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Internal\LazyInvoker;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Examples\Tasks\TaskApi;
use Shirakaba\Examples\Tasks\TaskSchema;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\UploadedFile;

/**
 * build(lazy: true) must behave exactly like build() for valid applications.
 * The example API exercises every extractor family.
 */
#[CoversClass(LazyInvoker::class)]
final class LazyBuildTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/shirakaba-lazy-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->directory);
    }

    /**
     * @return list<string>
     */
    private static function exercise(TestClient $client): array
    {
        $json = ['Content-Type' => 'application/json'];
        $responses = [
            $client->post('/tasks', '{"title":"Write"}', $json),
            $client->post('/tasks', '{"title":""}', $json),
            $client->get('/tasks?per_page=1&status=open'),
            $client->get('/tasks?per_page=0'),
            $client->get('/tasks/1'),
            $client->get('/tasks/0'),
            $client->patch('/tasks/1', '{"status":"done"}', $json),
            $client->form(
                'POST',
                '/tasks/1/attachments',
                ['note' => 'n'],
                ['file' => new UploadedFile(Stream::fromString("%PDF-1.4\n"), 9, UPLOAD_ERR_OK, 'a.pdf')],
            ),
            $client->delete('/tasks/1'),
            $client->get('/tasks/1'),
            $client->post('/tasks/1'),
        ];

        // Creation times come from the system clock and differ between the two runs.
        return array_map(
            static fn (ResponseInterface $response): string => (string) preg_replace(
                '/"created_at":"[^"]+"/',
                '"created_at":"…"',
                $response->getStatusCode() . ' ' . $response->getHeaderLine('Location') . ' ' . $response->getBody(),
            ),
            $responses,
        );
    }

    public function testLazyBuildBehavesLikeAnEagerOne(): void
    {
        $eager = self::exercise(new TestClient(TaskApi::builder(TaskSchema::database($this->directory . '/a.sqlite'))->build()));
        $lazy = self::exercise(new TestClient(TaskApi::builder(TaskSchema::database($this->directory . '/b.sqlite'))->build(lazy: true)));

        self::assertSame($eager, $lazy);
    }

    public function testLazyBuildMovesHandlerErrorsToRequestTime(): void
    {
        $app = new AppBuilder();
        $app->get('/ok', static fn (): Text => new Text('ok'));
        $app->get('/broken', static fn (int $id): Text => new Text('never'));

        $client = new TestClient($app->build(lazy: true));
        self::assertSame('ok', (string) $client->get('/ok')->getBody());
        self::assertSame(500, $client->get('/broken')->getStatusCode());

        $this->expectException(InvalidApplication::class);
        $app->build();
    }
}
