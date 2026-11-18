<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;
use Shirakaba\OpenApi\Tests\Fixtures\Activity;
use Shirakaba\OpenApi\Tests\Fixtures\Unfollowed;
use Shirakaba\Streaming\Sse;
use Shirakaba\Streaming\SseResponse;

#[CoversClass(Generator::class)]
final class EventStreamSchemaTest extends TestCase
{
    public function testDeclaredEventsBecomeAnItemSchema(): void
    {
        $app = new AppBuilder();
        $app->get(
            '/stream',
            #[SseResponse(['activity' => Activity::class, 'unfollowed' => Unfollowed::class, 'ping' => 'string'], 'Live activity.')]
            static fn (): Sse => new Sse([]),
        );
        $json = json_decode(new Generator(new Info('Stream', '1'))->generate($app->describe())->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['paths'] ?? null);
        self::assertIsArray($json['paths']['/stream'] ?? null);
        self::assertIsArray($json['paths']['/stream']['get'] ?? null);
        self::assertIsArray($json['paths']['/stream']['get']['responses'] ?? null);
        $response = $json['paths']['/stream']['get']['responses']['200'] ?? null;
        self::assertIsArray($response);
        self::assertSame('Live activity.', $response['description'] ?? null);
        self::assertIsArray($response['content'] ?? null);

        self::assertSame([
            'schema' => ['type' => 'string'],
            'x-itemSchema' => [
                'type' => 'object',
                'properties' => [
                    'event' => ['type' => 'string'],
                    'data' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'retry' => ['type' => 'integer', 'minimum' => 0],
                ],
                'required' => ['event', 'data'],
                'oneOf' => [
                    ['properties' => [
                        'event' => ['const' => 'activity'],
                        'data' => ['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['$ref' => '#/components/schemas/ActivityItem']],
                    ]],
                    ['properties' => [
                        'event' => ['const' => 'unfollowed'],
                        'data' => ['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['$ref' => '#/components/schemas/Unfollowed']],
                    ]],
                    ['properties' => ['event' => ['const' => 'ping'], 'data' => ['type' => 'string']]],
                ],
            ],
        ], $response['content']['text/event-stream'] ?? null);

        self::assertIsArray($json['components'] ?? null);
        self::assertIsArray($json['components']['schemas'] ?? null);
        self::assertArrayHasKey('Unfollowed', $json['components']['schemas'], 'Event-only DTOs become components.');
        self::assertArrayHasKey('Followed', $json['components']['schemas']);
    }
}
