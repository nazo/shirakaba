<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonMapper;
use Shirakaba\Json\Tests\Fixtures\Ping;

#[CoversClass(Json::class)]
#[CoversClass(JsonMapper::class)]
final class EmptyDtoTest extends TestCase
{
    public function testADtoWithoutFieldsIsAnObjectAndRoundTrips(): void
    {
        self::assertSame('{}', Json::encode(new Ping()));
        self::assertSame('[{}]', Json::encode([new Ping()]));
        self::assertEquals(new Ping(), JsonMapper::decode(Json::encode(new Ping()), Ping::class));
    }
}
