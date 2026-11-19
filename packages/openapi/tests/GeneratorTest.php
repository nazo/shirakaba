<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\OpenApi\Document;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\GeneratorState;

#[CoversClass(Generator::class)]
#[CoversClass(GeneratorState::class)]
#[CoversClass(Document::class)]
final class GeneratorTest extends TestCase
{
    private static function document(): Document
    {
        $app = require __DIR__ . '/Fixtures/app.php';
        $generator = require __DIR__ . '/Fixtures/openapi.php';
        self::assertInstanceOf(AppBuilder::class, $app);
        self::assertInstanceOf(Generator::class, $generator);

        return $generator->generate($app->describe());
    }

    /**
     * Decodes the JSON output, which is what clients see.
     *
     * @return array<string, mixed>
     */
    private static function json(): array
    {
        $decoded = json_decode(self::document()->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function at(mixed $document, string ...$path): mixed
    {
        foreach ($path as $segment) {
            self::assertIsArray($document, sprintf('Missing "%s".', $segment));
            self::assertArrayHasKey($segment, $document);
            $document = $document[$segment];
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    private static function keys(mixed $value): array
    {
        self::assertIsArray($value);

        return array_map(static fn (int|string $key): string => (string) $key, array_keys($value));
    }

    public function testDatesHaveFormats(): void
    {
        $app = new AppBuilder();
        $app->get(
            '/days/{day}',
            static fn (
                #[\Shirakaba\Core\Extraction\Path] \Shirakaba\Time\LocalDate $day,
                #[\Shirakaba\Core\Extraction\Query] ?\DateTimeImmutable $since = null,
            ): \Shirakaba\Core\Http\Text => new \Shirakaba\Core\Http\Text(''),
        );
        $json = json_decode(
            new Generator(new \Shirakaba\OpenApi\Info('Days', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            [
                ['name' => 'day', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                ['name' => 'since', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date-time']],
            ],
            self::at($json, 'paths', '/days/{day}', 'get', 'parameters'),
        );
    }

    public function testNullableParametersAreOptionalOrRequiredButNeverNull(): void
    {
        $app = new AppBuilder();
        $app->get(
            '/pages',
            static fn (
                #[\Shirakaba\Core\Extraction\Query] ?int $cursor,
                #[\Shirakaba\Core\Extraction\Query] ?int $until = null,
                #[\Shirakaba\Core\Extraction\Header('X-Limit')] ?int $limit = 20,
            ): \Shirakaba\Core\Http\Text => new \Shirakaba\Core\Http\Text(''),
        );
        $json = json_decode(
            new Generator(new \Shirakaba\OpenApi\Info('Pages', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            [
                ['name' => 'cursor', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                ['name' => 'until', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                ['name' => 'X-Limit', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20]],
            ],
            self::at($json, 'paths', '/pages', 'get', 'parameters'),
        );
    }

    public function testPropertyDocCommentsBecomeDescriptions(): void
    {
        $app = new AppBuilder();
        $app->get(
            '/described',
            #[\Shirakaba\Json\JsonResponse(200, Fixtures\Described::class)] static fn (): \Shirakaba\Json\Json => new \Shirakaba\Json\Json(null),
        );
        $json = json_decode(
            new Generator(new \Shirakaba\OpenApi\Info('Described', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            [
                'title' => ['type' => 'string', 'description' => 'Shown as is.'],
                'owner' => [
                    'anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']],
                    'description' => "Null when the owner was deleted, or never set.\n\nOwners are people.",
                ],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'count' => ['type' => 'integer'],
            ],
            self::at($json, 'components', 'schemas', 'Described', 'properties'),
        );
    }

    public function testResponseSchemasRequireEveryFieldAndUseTheDeclaredName(): void
    {
        $app = new AppBuilder();
        $app->post(
            '/notes',
            #[\Shirakaba\Json\JsonResponse(201, Fixtures\Draft::class)]
            static fn (#[\Shirakaba\Json\JsonBody] Fixtures\Draft $draft): \Shirakaba\Json\Json => new \Shirakaba\Json\Json($draft),
        );
        $json = json_decode(
            new Generator(new \Shirakaba\OpenApi\Info('Notes', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // The request comes first, so it takes the declared name; the response gets a suffix.
        self::assertSame(['text'], self::at($json, 'components', 'schemas', 'Note', 'required'));
        self::assertSame(['text', 'pinned'], self::at($json, 'components', 'schemas', 'NoteOutput', 'required'));
    }

    public function testDocumentHeader(): void
    {
        $document = self::json();

        self::assertSame('3.1.1', $document['openapi'] ?? null);
        self::assertSame(['title' => 'Tasks', 'version' => '1.0.0', 'description' => 'A small task API.'], $document['info'] ?? null);
        self::assertSame([['url' => 'https://api.example.com']], $document['servers'] ?? null);
        self::assertSame(['/tasks', '/tasks/{id}', '/files/{path}', '/opaque'], self::keys(self::at($document, 'paths')));
    }

    public function testQueryParametersWithConstraintsDefaultsAndEnums(): void
    {
        $operation = self::at(self::json(), 'paths', '/tasks', 'get');

        self::assertSame('tasks.list', self::at($operation, 'operationId'));
        self::assertSame('List tasks', self::at($operation, 'summary'));
        self::assertSame(['tasks'], self::at($operation, 'tags'));
        self::assertSame(
            [
                ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['open', 'done']]],
            ],
            self::at($operation, 'parameters'),
        );
        self::assertSame(['200', '400', 'default'], self::keys(self::at($operation, 'responses')));
    }

    public function testJsonBodyBecomesAComponentSchema(): void
    {
        $document = self::json();
        $operation = self::at($document, 'paths', '/tasks', 'post');

        self::assertSame(
            ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NewTask']]]],
            self::at($operation, 'requestBody'),
        );
        self::assertSame(['200', '400', '413', '415', '422', 'default'], self::keys(self::at($operation, 'responses')));
        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                    'owner' => ['anyOf' => [['$ref' => '#/components/schemas/Owner'], ['type' => 'null']], 'default' => null],
                    'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                    'status' => ['type' => 'string', 'enum' => ['open', 'done'], 'default' => 'open'],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            self::at($document, 'components', 'schemas', 'NewTask'),
        );
        self::assertSame(
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'], 'additionalProperties' => false],
            self::at($document, 'components', 'schemas', 'Owner'),
        );
    }

    public function testPathAndHeaderParametersAndResponses(): void
    {
        $operation = self::at(self::json(), 'paths', '/tasks/{id}', 'delete');

        self::assertTrue(self::at($operation, 'deprecated'));
        self::assertSame(
            [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ['name' => 'If-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            self::at($operation, 'parameters'),
        );
        self::assertSame(['description' => 'Successful response.'], self::at($operation, 'responses', '204'));
        self::assertSame(['$ref' => '#/components/responses/Problem'], self::at($operation, 'responses', 'default'));
    }

    public function testCatchAllAndUnreadTemplateParameters(): void
    {
        $operation = self::at(self::json(), 'paths', '/files/{path}', 'get');

        self::assertSame(
            [[
                'name' => 'path',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'description' => 'Matches the rest of the path, including slashes.',
            ]],
            self::at($operation, 'parameters'),
        );
        self::assertSame(['schema' => ['type' => 'string']], self::at($operation, 'responses', '200', 'content', 'text/plain'));
    }

    public function testWarnsInsteadOfGuessing(): void
    {
        $document = self::document();

        self::assertSame(
            [
                'Route "GET /opaque": parameter $value uses an extractor that cannot describe itself; it is left out.',
                'Route "PURGE /cache": OpenAPI 3.1 cannot describe the method PURGE.',
            ],
            $document->warnings,
        );
        $operation = self::at(self::json(), 'paths', '/opaque', 'get');
        self::assertIsArray($operation);
        self::assertArrayNotHasKey('parameters', $operation);
    }

    public function testUnconstrainedJsonResponsesAreObjectsNotArrays(): void
    {
        self::assertSame(
            ['description' => 'Any JSON value.'],
            self::at(self::json(), 'paths', '/tasks', 'get', 'responses', '200', 'content', 'application/json', 'schema'),
        );
        self::assertStringNotContainsString('"schema": []', self::document()->toJson());
    }

    public function testBearerAuthenticationBecomesASecurityRequirement(): void
    {
        $app = new AppBuilder();
        $app->get('/me', static fn (#[\Shirakaba\Auth\Authenticated] \stdClass $user): \Shirakaba\Core\Http\Text => new \Shirakaba\Core\Http\Text('me'));
        $app->get('/feed', static fn (#[\Shirakaba\Auth\Authenticated] ?\stdClass $user): \Shirakaba\Core\Http\Text => new \Shirakaba\Core\Http\Text('feed'));
        $document = json_decode(
            new Generator(new \Shirakaba\OpenApi\Info('A', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([['bearerAuth' => []]], self::at($document, 'paths', '/me', 'get', 'security'));
        self::assertSame([['bearerAuth' => []], []], self::at($document, 'paths', '/feed', 'get', 'security'));
        self::assertSame(['$ref' => '#/components/responses/Problem'], self::at($document, 'paths', '/me', 'get', 'responses', '401'));
        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], self::at($document, 'components', 'securitySchemes', 'bearerAuth'));
        self::assertArrayNotHasKey('parameters', (array) self::at($document, 'paths', '/me', 'get'));
    }

    public function testDeclaredResponsesReplaceGuessedOnes(): void
    {
        $app = new AppBuilder();
        $app->post('/tasks', #[\Shirakaba\Json\JsonResponse(201, \Shirakaba\OpenApi\Tests\Fixtures\Owner::class)]
        #[\Shirakaba\Core\Http\Responds(409, 'Duplicate.')] static fn (): \Shirakaba\Json\Json => new \Shirakaba\Json\Json(null, 201));
        $document = json_decode(
            new Generator(new \Shirakaba\OpenApi\Info('A', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $responses = self::at($document, 'paths', '/tasks', 'post', 'responses');

        self::assertSame(['201', '409', 'default'], self::keys($responses));
        self::assertSame(['$ref' => '#/components/schemas/Owner'], self::at($responses, '201', 'content', 'application/json', 'schema'));
        self::assertSame(['$ref' => '#/components/responses/Problem', 'description' => 'Duplicate.'], self::at($responses, '409'));
    }

    public function testSharedComponents(): void
    {
        $components = self::at(self::json(), 'components');

        self::assertSame(
            ['$ref' => '#/components/schemas/Problem'],
            self::at($components, 'responses', 'Problem', 'content', 'application/problem+json', 'schema'),
        );
        self::assertSame(['type', 'title', 'status'], self::at($components, 'schemas', 'Problem', 'required'));
    }
}
