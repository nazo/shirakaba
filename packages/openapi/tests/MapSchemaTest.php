<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonResponse;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;
use Shirakaba\OpenApi\Tests\Fixtures\Tally;

#[CoversClass(Generator::class)]
final class MapSchemaTest extends TestCase
{
    public function testMapsAreObjectsWithAdditionalProperties(): void
    {
        $app = new AppBuilder();
        $app->get('/tally', #[JsonResponse(200, Tally::class)] static fn (): Json => new Json(new Tally([], [])));
        $json = json_decode(
            new Generator(new Info('Tally', '1'))
                ->generate($app->describe())
                ->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($json);
        self::assertIsArray($json['components'] ?? null);
        self::assertIsArray($json['components']['schemas'] ?? null);
        $tally = $json['components']['schemas']['Tally'] ?? null;
        self::assertIsArray($tally);

        self::assertSame(
            [
                'reactions' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'users' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Followed']],
            ],
            $tally['properties'] ?? null,
        );
    }
}
