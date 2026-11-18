<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Description\MapType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Json\Internal\MapNode;
use Shirakaba\Json\Internal\Planner;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonMapper;
use Shirakaba\Json\JsonResponse;
use Shirakaba\Json\MapOf;
use Shirakaba\Json\Tests\Fixtures\Address;
use Shirakaba\Json\Tests\Fixtures\Reactions;
use UnexpectedValueException;

#[CoversClass(MapOf::class)]
#[CoversClass(MapNode::class)]
#[CoversClass(Planner::class)]
#[CoversClass(Json::class)]
final class MapOfTest extends TestCase
{
    public function testEncodesMapsAsObjectsEvenWhenEmptyOrNumeric(): void
    {
        self::assertSame('{"counts":{"👍":2,"🎉":1},"places":null}', Json::encode(new Reactions(['👍' => 2, '🎉' => 1])));
        self::assertSame('{"counts":{},"places":{"0":{"city":"Tokyo","zip":"100-0001"}}}', Json::encode(new Reactions([], [new Address('Tokyo', '100-0001')])));
    }

    public function testDecodesObjectsWithAnyKeys(): void
    {
        $decoded = JsonMapper::decode('{"counts":{"👍":2,"1":3},"places":{"home":{"city":"Tokyo","zip":"100-0001"}}}', Reactions::class);

        self::assertSame(['👍' => 2, 1 => 3], $decoded->counts);
        self::assertEquals(['home' => new Address('Tokyo', '100-0001')], $decoded->places);
        self::assertEquals(new Reactions([]), JsonMapper::decode('{"counts":{}}', Reactions::class));
    }

    public function testReportsEachBadValueByItsKey(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('/counts/a~1b must be an integer; /places must be an object or null');
        JsonMapper::decode('{"counts":{"a/b":"x"},"places":[]}', Reactions::class);
    }

    public function testRejectsAnArrayForAMap(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('/counts must be an object');
        JsonMapper::decode('{"counts":[1]}', Reactions::class);
    }

    public function testDescribesAMapType(): void
    {
        $type = new JsonResponse(200, Reactions::class)->responseDescription()->body;

        self::assertInstanceOf(ObjectType::class, $type);
        self::assertEquals(new MapType(new ScalarType('int')), ($type->properties[0] ?? null)?->type);
    }
}
