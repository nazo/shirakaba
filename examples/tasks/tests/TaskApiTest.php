<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Examples\Tasks\TaskQueries;
use Shirakaba\Examples\Tasks\TaskCommands;
use Shirakaba\Examples\Tasks\TaskSchema;
use Shirakaba\Examples\Tasks\TaskView;
use Shirakaba\Examples\Tasks\TaskApi;
use Shirakaba\Examples\Tasks\TaskHandlers;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\UploadedFile;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(TaskApi::class)]
#[CoversClass(TaskHandlers::class)]
#[CoversClass(TaskQueries::class)]
#[CoversClass(TaskCommands::class)]
#[CoversClass(TaskSchema::class)]
#[CoversClass(TaskView::class)]
final class TaskApiTest extends TestCase
{
    private TestClient $client;
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/shirakaba-tasks-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->client = new TestClient(TaskApi::builder(TaskSchema::database($this->file))->build());
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The response body without the creation time, which comes from the system clock.
     *
     * @return array<array-key, mixed>
     */
    private static function task(ResponseInterface $response): array
    {
        $task = self::json($response);
        self::assertIsString($task['created_at'] ?? null);
        unset($task['created_at']);

        return $task;
    }

    private function create(string $title): ResponseInterface
    {
        return $this->client->post('/tasks', (string) json_encode(['title' => $title]), ['Content-Type' => 'application/json']);
    }

    public function testCreatesAndShowsATask(): void
    {
        $created = $this->create('Write docs');

        self::assertSame(201, $created->getStatusCode());
        self::assertSame('/tasks/1', $created->getHeaderLine('Location'));
        self::assertSame(['id' => 1, 'title' => 'Write docs', 'description' => '', 'status' => 'open', 'version' => 1], self::task($created));
        self::assertSame(self::json($created), self::json($this->client->get('/tasks/1')));
    }

    public function testListsWithPagingAndFiltering(): void
    {
        foreach (['a', 'b', 'c'] as $title) {
            $this->create($title);
        }
        $this->client->post('/tasks/2/complete');

        $page = $this->client->get('/tasks?page=2&per_page=2');
        self::assertSame([2, 2, 3], [ResponseJson::at($page, '/page'), ResponseJson::at($page, '/per_page'), ResponseJson::at($page, '/total')]);
        self::assertSame('c', ResponseJson::at($page, '/items/0/title'));

        self::assertSame(1, ResponseJson::at($this->client->get('/tasks?status=done'), '/total'));
        self::assertSame(400, $this->client->get('/tasks?per_page=500')->getStatusCode());
        self::assertSame(400, $this->client->get('/tasks?status=archived')->getStatusCode());
    }

    public function testReplacesAndDeletes(): void
    {
        $this->create('Draft');

        $replaced = $this->client->put('/tasks/1', (string) json_encode(['title' => 'Final', 'description' => 'Done right', 'status' => 'done', 'version' => 1]), ['Content-Type' => 'application/json']);
        self::assertSame(['id' => 1, 'title' => 'Final', 'description' => 'Done right', 'status' => 'done', 'version' => 2], self::task($replaced));

        self::assertSame(204, $this->client->delete('/tasks/1')->getStatusCode());
        self::assertSame(404, $this->client->get('/tasks/1')->getStatusCode());
        self::assertSame(404, $this->client->delete('/tasks/1')->getStatusCode());
    }

    public function testPatchChangesOnlySentFields(): void
    {
        $this->client->post('/tasks', (string) json_encode(['title' => 'Draft', 'description' => 'Keep me']), ['Content-Type' => 'application/json']);

        $patched = $this->client->patch('/tasks/1', '{"status":"done"}', ['Content-Type' => 'application/json']);
        self::assertSame(['id' => 1, 'title' => 'Draft', 'description' => 'Keep me', 'status' => 'done', 'version' => 2], self::task($patched));

        $blank = $this->client->patch('/tasks/1', '{"title":""}', ['Content-Type' => 'application/json']);
        self::assertSame(422, $blank->getStatusCode());
        self::assertSame(400, $this->client->patch('/tasks/1', '{"title":null}', ['Content-Type' => 'application/json'])->getStatusCode());
    }

    public function testRejectsWritesBasedOnAnOutdatedVersion(): void
    {
        $this->create('Shared');
        $this->client->patch('/tasks/1', '{"title":"First writer","version":1}', ['Content-Type' => 'application/json']);

        $stale = $this->client->put('/tasks/1', (string) json_encode(['title' => 'Second writer', 'description' => '', 'status' => 'open', 'version' => 1]), ['Content-Type' => 'application/json']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('The task was changed by someone else; reload it and try again.', ResponseJson::at($stale, '/detail'));
        self::assertSame('First writer', ResponseJson::at($this->client->get('/tasks/1'), '/title'));
        self::assertSame(409, $this->client->patch('/tasks/1', '{"status":"done","version":1}', ['Content-Type' => 'application/json'])->getStatusCode());
        self::assertSame(404, $this->client->patch('/tasks/9', '{"version":1}', ['Content-Type' => 'application/json'])->getStatusCode());
    }

    public function testCompletesAllOpenTasksInOneStatement(): void
    {
        foreach (['a', 'b', 'c'] as $title) {
            $this->create($title);
        }
        $this->client->post('/tasks/2/complete');

        $completed = $this->client->post('/tasks/complete-all');
        self::assertSame(['completed' => 2], self::json($completed));
        self::assertSame(3, ResponseJson::at($this->client->get('/tasks?status=done'), '/total'));
        self::assertSame([2, 2, 2], array_map(
            static fn (mixed $task): mixed => is_array($task) ? $task['version'] ?? null : null,
            (array) ResponseJson::at($this->client->get('/tasks'), '/items'),
        ), 'The bulk update increases each version, so older copies are detected.');
    }

    public function testAcceptsAttachmentsCheckedByContent(): void
    {
        $this->create('With file');
        $file = static fn (string $content, string $name): UploadedFile => new UploadedFile(Stream::fromString($content), strlen($content), UPLOAD_ERR_OK, $name, 'application/pdf');

        $accepted = $this->client->form('POST', '/tasks/1/attachments', ['note' => 'spec'], ['file' => $file("%PDF-1.4\n%%EOF\n", 'spec.pdf')]);
        self::assertSame(201, $accepted->getStatusCode());
        self::assertSame(['task' => 1, 'name' => 'spec.pdf', 'size' => 15, 'note' => 'spec'], ResponseJson::decode($accepted));

        $disguised = $this->client->form('POST', '/tasks/1/attachments', [], ['file' => $file('<?php echo 1;', 'spec.pdf')]);
        self::assertSame(422, $disguised->getStatusCode());
        self::assertSame(404, $this->client->form('POST', '/tasks/9/attachments', [], ['file' => $file("%PDF-1.4\n", 'a.pdf')])->getStatusCode());
    }

    public function testValidatesInput(): void
    {
        $blank = $this->create('   ');
        self::assertSame(422, $blank->getStatusCode());
        self::assertSame([['pointer' => '/title', 'detail' => 'must not be blank']], self::withoutCodes(ResponseJson::at($blank, '/errors')));

        self::assertSame(400, $this->client->get('/tasks/0')->getStatusCode());
        self::assertSame(415, $this->client->post('/tasks', 'title=x', ['Content-Type' => 'application/x-www-form-urlencoded'])->getStatusCode());
        self::assertSame(405, $this->client->post('/tasks/1')->getStatusCode());
    }

    public function testCommittedOpenApiDocumentMatchesTheOpenApiSchema(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../../../packages/openapi/tests/Fixtures/oas-3.1-schema.json');
        // Same workarounds as packages/openapi/tests/SpecificationTest.php.
        $schema = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', $schema);
        $validator = new \Opis\JsonSchema\CompliantValidator();
        $validator->resolver()?->registerRaw($schema, 'https://spec.openapis.org/oas/3.1/schema/2022-10-07');

        $document = json_decode((string) file_get_contents(__DIR__ . '/../openapi.json'), false, 512, JSON_THROW_ON_ERROR);
        $error = $validator->validate($document, 'https://spec.openapis.org/oas/3.1/schema/2022-10-07')->error();

        self::assertNull($error, $error === null ? '' : (string) json_encode(new \Opis\JsonSchema\Errors\ErrorFormatter()->format($error)));
    }

    public function testResponsesMatchTheirDeclaredSchemas(): void
    {
        $validator = new \Opis\JsonSchema\Validator();
        $document = (string) file_get_contents(__DIR__ . '/../openapi.json');
        $validator->resolver()?->registerRaw($document, 'https://shirakaba.example/tasks/openapi.json');
        $check = static function (ResponseInterface $response, string $schema, int $status) use ($validator): void {
            self::assertSame($status, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
            $error = $validator->validate($body, 'https://shirakaba.example/tasks/openapi.json#/components/schemas/' . $schema)->error();
            self::assertNull($error, $error === null ? '' : (string) json_encode(new \Opis\JsonSchema\Errors\ErrorFormatter()->format($error)));
        };

        $check($this->create('Typed'), 'Task', 201);
        $check($this->client->get('/tasks/1'), 'Task', 200);
        $check($this->client->get('/tasks'), 'TaskPage', 200);
        $check($this->client->get('/tasks/9'), 'Problem', 404);
        $check($this->client->post('/tasks/complete-all'), 'CompletedTasks', 200);
        $pdf = new UploadedFile(Stream::fromString("%PDF-1.4\n%%EOF\n"), 15, UPLOAD_ERR_OK, 'a.pdf');
        $check($this->client->form('POST', '/tasks/1/attachments', [], ['file' => $pdf]), 'AttachmentReceipt', 201);
    }

    public function testCommittedOpenApiDocumentIsUpToDate(): void
    {
        $generator = require __DIR__ . '/../openapi.php';
        self::assertInstanceOf(\Shirakaba\OpenApi\Generator::class, $generator);
        $document = $generator->generate(TaskApi::builder(TaskSchema::database(':memory:'))->describe());

        self::assertSame([], $document->warnings);
        self::assertSame(
            $document->toJson(),
            file_get_contents(__DIR__ . '/../openapi.json'),
            'Regenerate with: composer examples:openapi',
        );
    }

    /**
     * The field errors without their codes, which the code tests check.
     */
    private static function withoutCodes(mixed $errors): mixed
    {
        return is_array($errors)
            ? array_map(static fn (mixed $error): mixed => is_array($error) ? array_diff_key($error, ['code' => true]) : $error, $errors)
            : $errors;
    }
}
