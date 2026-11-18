<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ConstType;
use Shirakaba\Core\Description\EventChannelDescription;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\Property;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Description\UnionType;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;

#[CoversClass(Generator::class)]
final class EventChannelSchemaTest extends TestCase
{
    public function testWritesChannelsAndWarnsAboutDuplicateNames(): void
    {
        $pinged = new ObjectType(\stdClass::class, [new Property('type', new ConstType('pinged'), true), new Property('at', new ScalarType('string', format: 'date-time'), true)], name: 'Pinged', output: true);
        $channel = new EventChannelDescription('pings', 'pings', [], false, '', new UnionType(\ArrayObject::class, 'type', ['pinged' => $pinged], name: 'PingsEvent', output: true));
        $document = new Generator(new Info('Pings', '1'), eventChannels: [$channel, $channel])->generate(new AppBuilder()->describe());
        $json = json_decode($document->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        $channels = $json['x-event-channels'] ?? null;
        self::assertIsArray($channels);
        self::assertSame(['channel' => 'pings', 'parameters' => [], 'private' => false, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PingsEvent']]]], $channels['pings'] ?? null);
        self::assertStringContainsString('"parameters": {}', $document->toJson(), 'No parameters is an empty object, not a list.');
        self::assertSame(['Two event channels are named "pings"; the second is left out.'], $document->warnings);
    }
}
