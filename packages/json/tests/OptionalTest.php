<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\Optional;
use Shirakaba\Json\OptionalOf;
use Shirakaba\Json\Tests\Fixtures\OptionalWithoutType;
use Shirakaba\Json\Tests\Fixtures\Patch;

#[CoversClass(Optional::class)]
#[CoversClass(OptionalOf::class)]
final class OptionalTest extends TestCase
{
    private static function client(): TestClient
    {
        $app = new AppBuilder();
        $app->patch('/', static fn (#[JsonBody] Patch $patch): Json => new Json([
            'nickname' => $patch->nickname->isPresent() ? ['sent' => $patch->nickname->get()] : 'absent',
            'age' => $patch->age->or('absent'),
            'role' => $patch->role->isPresent() ? $patch->role->get()->value : 'absent',
        ]));

        return new TestClient($app->build());
    }

    public function testDistinguishesAbsentNullAndValues(): void
    {
        $client = self::client();
        $patch = static fn (string $body) => $client->patch('/', $body, ['Content-Type' => 'application/json']);

        self::assertSame(['nickname' => 'absent', 'age' => 'absent', 'role' => 'absent'], ResponseJson::decode($patch('{}')));
        self::assertSame(['nickname' => ['sent' => null], 'age' => 3, 'role' => 'admin'], ResponseJson::decode($patch('{"nickname":null,"age":3,"role":"admin"}')));
        self::assertSame(400, $patch('{"age":null}')->getStatusCode(), 'Null is accepted only for nullable optionals.');
        self::assertSame(422, $patch('{"age":-1}')->getStatusCode(), 'Constraints apply to the value.');
    }

    public function testAbsentValueCannotBeRead(): void
    {
        $this->expectException(LogicException::class);
        new Optional()->get();
    }

    public function testDescribedAsNotRequiredWithoutDefault(): void
    {
        $app = new AppBuilder();
        $app->patch('/', static fn (#[JsonBody] Patch $patch): Json => new Json(null));
        $type = ($app->describe()->routes[0]->parameters[0] ?? null)?->type;
        self::assertInstanceOf(ObjectType::class, $type);

        foreach ($type->properties as $property) {
            self::assertFalse($property->required);
            self::assertFalse($property->hasDefault);
        }
        self::assertTrue(($type->properties[0] ?? null)?->type->isNullable());
    }

    public function testOptionalNeedsItsValueType(): void
    {
        $app = new AppBuilder();
        $app->patch('/', static fn (#[JsonBody] OptionalWithoutType $patch): Json => new Json(null));

        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage('add #[OptionalOf(...)]');
        $app->build();
    }
}
