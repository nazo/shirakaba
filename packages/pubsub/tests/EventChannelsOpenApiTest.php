<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;
use Shirakaba\PubSub\EventChannels;
use Shirakaba\PubSub\Tests\Fixtures\Events\BoardChannel;
use Shirakaba\PubSub\Tests\Fixtures\Events\BoardUserChannel;

/**
 * The document format agreed with tsurara (design/11-real-time-events.md), end to end.
 */
#[CoversNothing]
final class EventChannelsOpenApiTest extends TestCase
{
    public function testWritesTheAgreedExtension(): void
    {
        $generator = new Generator(new Info('Boards', '1'), eventChannels: EventChannels::describe(BoardChannel::class, BoardUserChannel::class));
        $document = json_decode($generator->generate(new AppBuilder()->describe())->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        self::assertSame(
            [
                'board' => [
                    'summary' => 'Updates every participant of a board may see',
                    'channel' => 'board/{boardId}',
                    'parameters' => ['boardId' => ['type' => 'string']],
                    'private' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/BoardEvent']]],
                ],
                'boardUser' => [
                    'channel' => 'board/{boardId}/user/{userId}',
                    'parameters' => ['boardId' => ['type' => 'string'], 'userId' => ['type' => 'integer']],
                    'private' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/BoardUserEvent']]],
                ],
            ],
            $document['x-event-channels'] ?? null,
        );

        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $cardAdded = $schemas['CardAdded'] ?? null;
        self::assertIsArray($cardAdded);
        self::assertIsArray($cardAdded['properties'] ?? null);
        self::assertSame(
            [
                'oneOf' => [['$ref' => '#/components/schemas/CardAdded'], ['$ref' => '#/components/schemas/PhaseChanged']],
                'discriminator' => [
                    'propertyName' => 'type',
                    'mapping' => [
                        'card.added' => '#/components/schemas/CardAdded',
                        'phase.changed' => '#/components/schemas/PhaseChanged',
                    ],
                ],
            ],
            $schemas['BoardEvent'] ?? null,
        );
        self::assertSame(['type' => 'string', 'const' => 'card.added'], $cardAdded['properties']['type'] ?? null);
        self::assertSame(['type', 'seq', 'card'], $cardAdded['required'] ?? null);
        self::assertSame(['$ref' => '#/components/schemas/Card'], $cardAdded['properties']['card'] ?? null);
    }
}
