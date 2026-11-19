<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\ProblemCode;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\Tests\Fixtures\Event;

/**
 * Clients get stable codes for problems and field errors.
 */
#[CoversNothing]
final class ProblemCodesTest extends TestCase
{
    private static function client(): TestClient
    {
        $app = new AppBuilder();
        $app->post('/events', static fn (#[JsonBody] Event $event): Json => new Json('ok'));
        $app->get('/since', static fn (#[Query] DateTimeImmutable $since, #[Query] int $page = 1): Json => new Json('ok'));

        return new TestClient($app->build());
    }

    public function testBodyProblemsAndFieldErrorsCarryCodes(): void
    {
        $response = self::client()->post('/events', '{"title":"x","startsAt":"2026-11-11T09:30:00","day":"2026-11-11","extra":1}', [
            'Content-Type' => 'application/json',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProblemCode::INVALID_BODY, ResponseJson::at($response, '/code'));
        $errors = ResponseJson::at($response, '/errors');
        self::assertIsArray($errors);
        $codes = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            self::assertIsString($error['pointer'] ?? null);
            $codes[$error['pointer']] = $error['code'] ?? null;
        }
        self::assertSame(ProblemCode::INVALID_DATE_TIME, $codes['/startsAt'] ?? null);
        self::assertSame(ProblemCode::UNKNOWN_FIELD, $codes['/extra'] ?? null);
    }

    public function testRequiredFieldsAndMalformedBodies(): void
    {
        $missing = self::client()->post('/events', '{}', ['Content-Type' => 'application/json']);
        self::assertSame(ProblemCode::REQUIRED, ResponseJson::at($missing, '/errors/0/code'));

        self::assertSame(ProblemCode::MALFORMED_BODY, ResponseJson::at(self::client()->post('/events', '{', ['Content-Type' => 'application/json']), '/code'));
        self::assertSame(ProblemCode::UNSUPPORTED_MEDIA_TYPE, ResponseJson::at(self::client()->post('/events', 'x', [
            'Content-Type' => 'text/plain',
        ]), '/code'));
    }

    public function testParameterProblemsCarryCodes(): void
    {
        self::assertSame(ProblemCode::MISSING_PARAMETER, ResponseJson::at(self::client()->get('/since'), '/code'));
        self::assertSame(ProblemCode::INVALID_DATE_TIME, ResponseJson::at(self::client()->get('/since?since=2026-11-11T09:30:00'), '/code'));
        self::assertSame(ProblemCode::INVALID_PARAMETER, ResponseJson::at(self::client()->get('/since?since=2026-11-11T09:30:00Z&page=x'), '/code'));
        self::assertSame(ProblemCode::REPEATED_PARAMETER, ResponseJson::at(self::client()->get('/since?since=2026-11-11T09:30:00Z&page=1&page=2'), '/code'));
    }
}
