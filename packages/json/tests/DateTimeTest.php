<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Json\Internal\TimeNode;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\Tests\Fixtures\Event;
use Shirakaba\Time\LocalDate;

#[CoversClass(TimeNode::class)]
#[CoversClass(Json::class)]
final class DateTimeTest extends TestCase
{
    private static function client(): TestClient
    {
        $app = new AppBuilder();
        $app->post('/events', static fn (#[JsonBody] Event $event): Json => new Json($event));

        return new TestClient($app->build());
    }

    public function testMapsAndEncodesDatesAndInstants(): void
    {
        $response = self::client()->post(
            '/events',
            '{"title":"Launch","startsAt":"2026-11-11T09:30:00+09:00","day":"2026-11-11","holidays":["2026-11-23"]}',
            ['Content-Type' => 'application/json'],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            '{"title":"Launch","startsAt":"2026-11-11T00:30:00.000000Z","day":"2026-11-11","endsAt":null,"holidays":["2026-11-23"]}',
            (string) $response->getBody(),
        );
    }

    public function testRejectsDateTimesWithoutOffsetAndInvalidDates(): void
    {
        $response = self::client()->post(
            '/events',
            '{"title":"Launch","startsAt":"2026-11-11T09:30:00","day":"2026-02-30","endsAt":12}',
            ['Content-Type' => 'application/json'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            ['pointer' => '/startsAt', 'detail' => 'must be an RFC 3339 date-time with an offset, such as "2026-11-11T09:30:00Z"'],
            ['pointer' => '/day', 'detail' => 'must be a date such as "2026-11-11"'],
            ['pointer' => '/endsAt', 'detail' => 'must be an RFC 3339 date-time with an offset, such as "2026-11-11T09:30:00Z" or null'],
        ], self::withoutCodes(ResponseJson::at($response, '/errors')));
    }

    public function testEncodesAnyTimezoneAsUtc(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Json => new Json([
            'at' => new DateTimeImmutable('2026-11-11 09:30:00.5', new DateTimeZone('Asia/Tokyo')),
            'day' => LocalDate::of(2026, 11, 11),
        ]));

        self::assertSame('{"at":"2026-11-11T00:30:00.500000Z","day":"2026-11-11"}', (string) new TestClient($app->build())->get('/')->getBody());
    }

    public function testDescribesFormats(): void
    {
        $app = new AppBuilder();
        $app->post('/events', static fn (#[JsonBody] Event $event): Json => new Json(null));
        $type = (($app->describe()->routes[0] ?? null)?->parameters[0] ?? null)?->type;

        self::assertInstanceOf(ObjectType::class, $type);
        $types = [];
        foreach ($type->properties as $property) {
            $types[$property->name] = $property->type;
        }
        self::assertEquals(new ScalarType('string', false, 'date-time'), $types['startsAt'] ?? null);
        self::assertEquals(new ScalarType('string', false, 'date'), $types['day'] ?? null);
        self::assertEquals(new ScalarType('string', true, 'date-time'), $types['endsAt'] ?? null);
    }

    public function testRejectsMutableDateTimeFields(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[JsonBody] \Shirakaba\Json\Tests\Fixtures\MutableEvent $event): Json => new Json(null));

        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage('use DateTimeImmutable');
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
