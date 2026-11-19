<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Json\Internal\EnumNode;
use Shirakaba\Json\Internal\ListNode;
use Shirakaba\Json\Internal\ObjectNode;
use Shirakaba\Json\Internal\Planner;
use Shirakaba\Json\Internal\Problems;
use Shirakaba\Json\Internal\ScalarNode;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\JsonBodyFailure;
use Shirakaba\Json\Tests\Fixtures\Address;
use Shirakaba\Json\Tests\Fixtures\CreateUser;
use Shirakaba\Json\Tests\Fixtures\Recursive;
use Shirakaba\Json\Tests\Fixtures\UntypedList;

#[CoversClass(JsonBody::class)]
#[CoversClass(JsonBodyFailure::class)]
#[CoversClass(Planner::class)]
#[CoversClass(ObjectNode::class)]
#[CoversClass(ScalarNode::class)]
#[CoversClass(EnumNode::class)]
#[CoversClass(ListNode::class)]
#[CoversClass(Problems::class)]
final class JsonBodyTest extends TestCase
{
    private const string VALID = '{"name":"Alice","email":"alice@example.com","address":{"city":"Tokyo","zip":"100-0001"}}';

    private static function client(?JsonBody $body = null): TestClient
    {
        $app = new AppBuilder();
        $app->post(
            '/users',
            $body === null
                ? static fn (#[JsonBody] CreateUser $input): Json => new Json(self::summary($input))
                : static fn (#[JsonBody(maxBytes: 200)] CreateUser $input): Json => new Json(self::summary($input)),
        );

        return new TestClient($app->build());
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(CreateUser $user): array
    {
        return [
            'name' => $user->name,
            'age' => $user->age,
            'role' => $user->role,
            'city' => $user->address->city,
            'tags' => $user->tags,
            'previous' => count($user->previous),
            'score' => $user->score,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function post(string $body, string $contentType = 'application/json', ?TestClient $client = null): ResponseInterface
    {
        return ($client ?? self::client())->post('/users', $body, ['Content-Type' => $contentType]);
    }

    public function testMapsAValidDocument(): void
    {
        $response = self::post('{"name":"Alice","email":"alice@example.com","address":{"city":"Tokyo","zip":"100-0001"},'
            . '"age":30,"role":"admin","tags":["a","b"],"previous":[{"city":"Osaka","zip":"530-0001"}],"score":1}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['name' => 'Alice', 'age' => 30, 'role' => 'admin', 'city' => 'Tokyo', 'tags' => ['a', 'b'], 'previous' => 1, 'score' => 1.0],
            self::json($response),
        );
    }

    public function testUsesDefaultsForMissingOptionalFields(): void
    {
        self::assertSame(
            ['name' => 'Alice', 'age' => null, 'role' => 'member', 'city' => 'Tokyo', 'tags' => [], 'previous' => 0, 'score' => 0.0],
            self::json(self::post(self::VALID)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonMediaTypes(): iterable
    {
        yield 'plain' => ['application/json'];
        yield 'with charset' => ['application/json; charset=utf-8'];
        yield 'uppercase' => ['Application/JSON'];
        yield 'suffix' => ['application/vnd.example+json'];
    }

    #[DataProvider('jsonMediaTypes')]
    public function testAcceptsJsonMediaTypes(string $contentType): void
    {
        self::assertSame(200, self::post(self::VALID, $contentType)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string, int, string, list<array{pointer: string, detail: string}>|null}>
     */
    public static function failures(): iterable
    {
        yield 'wrong media type' => [self::VALID, 'text/plain', 415, 'Expected a JSON body', null];
        yield 'missing media type' => [self::VALID, '', 415, 'Expected a JSON body', null];
        yield 'malformed' => ['{"name":', 'application/json', 400, 'not valid JSON', null];
        yield 'invalid utf-8' => ["{\"name\":\"\xff\"}", 'application/json', 400, 'not valid JSON', null];
        yield 'array instead of object' => ['[]', 'application/json', 400, 'expected structure', [['pointer' => '/', 'detail' => 'must be an object']]];
        yield 'missing and mistyped fields' => [
            '{"name":1,"address":{"city":"Tokyo"},"age":"30","extra":true}',
            'application/json',
            400,
            'expected structure',
            [
                ['pointer' => '/name', 'detail' => 'must be a string'],
                ['pointer' => '/email', 'detail' => 'is required'],
                ['pointer' => '/address/zip', 'detail' => 'is required'],
                ['pointer' => '/age', 'detail' => 'must be an integer or null'],
                ['pointer' => '/extra', 'detail' => 'is not an accepted field'],
            ],
        ];
        yield 'float for int' => [
            '{"name":"A","email":"a@example.com","address":{"city":"T","zip":"100-0001"},"age":1.5}',
            'application/json',
            400,
            'expected structure',
            [
                ['pointer' => '/age', 'detail' => 'must be an integer or null'],
            ],
        ];
        yield 'bad enum and list item' => [
            '{"name":"A","email":"a@example.com","address":{"city":"T","zip":"100-0001"},"role":"root","tags":["a",2],"previous":{}}',
            'application/json',
            400,
            'expected structure',
            [
                ['pointer' => '/role', 'detail' => 'must be one of: "member", "admin"'],
                ['pointer' => '/tags/1', 'detail' => 'must be a string'],
                ['pointer' => '/previous', 'detail' => 'must be an array'],
            ],
        ];
        yield 'escaped pointer' => [
            '{"name":"A","email":"a@example.com","address":{"city":"T","zip":"100-0001"},"a/b~c":1}',
            'application/json',
            400,
            'expected structure',
            [
                ['pointer' => '/a~1b~0c', 'detail' => 'is not an accepted field'],
            ],
        ];
        yield 'constraint violations' => [
            '{"name":"","email":"nope","address":{"city":"T","zip":"1000001"},"age":200,"tags":["a","b","c","d"]}',
            'application/json',
            422,
            'invalid values',
            [
                ['pointer' => '/name', 'detail' => 'must be at least 1 characters long'],
                ['pointer' => '/email', 'detail' => 'must be an e-mail address'],
                ['pointer' => '/address/zip', 'detail' => 'must look like 123-4567'],
                ['pointer' => '/age', 'detail' => 'must be at most 150'],
                ['pointer' => '/tags', 'detail' => 'must have at most 3 items'],
            ],
        ];
        yield 'dto invariant' => [
            '{"name":"A","email":"a@example.com","address":{"city":"T","zip":"100-0001"},"age":10,"role":"admin"}',
            'application/json',
            422,
            'invalid values',
            [
                ['pointer' => '/', 'detail' => 'is invalid'],
            ],
        ];
    }

    /**
     * @param list<array{pointer: string, detail: string}>|null $errors
     */
    #[DataProvider('failures')]
    public function testRejectsAsDocumented(string $body, string $contentType, int $status, string $detail, ?array $errors): void
    {
        $response = self::post($body, $contentType);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $problem = self::json($response);
        self::assertIsString($problem['detail'] ?? null);
        self::assertStringContainsString($detail, $problem['detail']);
        self::assertSame($errors, self::withoutCodes($problem['errors'] ?? null));
        self::assertStringNotContainsString('internal rule', (string) $response->getBody());
    }

    public function testRejectsBodiesOverTheLimit(): void
    {
        $client = self::client(new JsonBody(maxBytes: 200));
        $large = '{"name":"' . str_repeat('a', 300) . '"}';

        self::assertSame(413, self::post($large, client: $client)->getStatusCode());
        self::assertSame(413, $client->post('/users', self::VALID, ['Content-Type' => 'application/json', 'Content-Length' => '99999'])->getStatusCode());
        self::assertSame(200, self::post(self::VALID, client: $client)->getStatusCode());
    }

    public function testRejectsDocumentsDeeperThanTheLimit(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[JsonBody(maxDepth: 2)] CreateUser $input): Json => new Json(null));
        $response = new TestClient($app->build())->post('/', self::VALID, ['Content-Type' => 'application/json']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testLimitsObjectMembers(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[JsonBody(allowUnknownFields: true, maxMembers: 3)] Address $input): Json => new Json($input->city));
        $client = new TestClient($app->build());
        $headers = ['Content-Type' => 'application/json'];

        // Colons inside strings, escaped quotes included, are not members.
        self::assertSame(200, $client->post('/', '{"city":"a:b\\":c","zip":"100-0001","x":"1:2"}', $headers)->getStatusCode());
        self::assertSame(413, $client->post('/', '{"city":"Tokyo","zip":"100-0001","x":{"y":1}}', $headers)->getStatusCode());
    }

    public function testReportsABoundedNumberOfErrors(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[JsonBody] Address $input): Json => new Json($input->city));
        $members = implode(',', array_map(static fn (int $i): string => sprintf('"x%d":1', $i), range(1, 500)));
        $response = new TestClient($app->build())->post('/', '{"city":"Tokyo","zip":"100-0001",' . $members . '}', ['Content-Type' => 'application/json']);
        $errors = ResponseJson::at($response, '/errors');

        self::assertSame(400, $response->getStatusCode());
        self::assertIsArray($errors);
        self::assertCount(100, $errors);
    }

    public function testAllowsUnknownFieldsWhenAsked(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[JsonBody(allowUnknownFields: true)] Address $input): Json => new Json($input->city));
        $response = new TestClient($app->build())->post('/', '{"city":"Tokyo","zip":"100-0001","extra":1}', ['Content-Type' => 'application/json']);

        self::assertSame('"Tokyo"', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{Closure, string}>
     */
    public static function invalidDtos(): iterable
    {
        yield 'recursive' => [static fn (#[JsonBody] Recursive $input): Json => new Json(null), 'refers to itself'];
        yield 'untyped list' => [static fn (#[JsonBody] UntypedList $input): Json => new Json(null), 'add #[ListOf(...)]'];
        yield 'scalar parameter' => [static fn (#[JsonBody] string $input): Json => new Json(null), 'needs a non-nullable DTO class'];
        yield 'nullable parameter' => [static fn (#[JsonBody] ?Address $input): Json => new Json(null), 'needs a non-nullable DTO class'];
        yield 'interface' => [static fn (#[JsonBody] \Countable $input): Json => new Json(null), 'Countable is not a class'];
        yield 'abstract class' => [static fn (#[JsonBody] \FilterIterator $input): Json => new Json(null), 'cannot be instantiated'];
    }

    #[DataProvider('invalidDtos')]
    public function testRejectsUnmappableDtosAtBuildTime(Closure $handler, string $message): void
    {
        $app = new AppBuilder();
        $app->post('/', $handler);

        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage($message);
        $app->build();
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
