<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\JsonResponse;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;
use Shirakaba\OpenApi\Tests\Fixtures\Activity;

#[CoversClass(Generator::class)]
final class UnionSchemaTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function schemas(): array
    {
        $app = new AppBuilder();
        $app->get('/activities', #[JsonResponse(200, Activity::class, list: true)] static fn (): Json => new Json([]));
        $app->post('/activities', #[JsonResponse(201, Activity::class)] static fn (#[JsonBody] Activity $input): Json => new Json($input, 201));
        $json = json_decode(new Generator(new Info('Activities', '1'))->generate($app->describe())->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['components'] ?? null);
        self::assertIsArray($json['components']['schemas'] ?? null);

        /** @var array<string, mixed> */
        return $json['components']['schemas'];
    }

    public function testUnionsAreOneOfWithADiscriminatorMapping(): void
    {
        $schemas = self::schemas();

        self::assertSame([
            'oneOf' => [
                ['$ref' => '#/components/schemas/Followed'],
                ['$ref' => '#/components/schemas/Reacted'],
            ],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => [
                    'follow' => '#/components/schemas/Followed',
                    'reaction' => '#/components/schemas/Reacted',
                ],
            ],
        ], $schemas['ActivityItem'] ?? null);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'const' => 'follow'],
                'user' => ['type' => 'string'],
            ],
            'required' => ['kind', 'user'],
        ], $schemas['Followed'] ?? null);
    }

    public function testInputMembersKeepDefaultsAndConstraints(): void
    {
        $schemas = self::schemas();

        $union = $schemas['ActivityItemInput'] ?? null;
        self::assertIsArray($union);
        self::assertIsArray($union['discriminator'] ?? null);
        self::assertSame(
            ['follow' => '#/components/schemas/FollowedInput', 'reaction' => '#/components/schemas/ReactedInput'],
            $union['discriminator']['mapping'] ?? null,
        );
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'const' => 'reaction'],
                'user' => ['type' => 'string'],
                'reaction' => ['type' => 'string', 'maxLength' => 8, 'default' => '+1'],
            ],
            'required' => ['kind', 'user'],
            'additionalProperties' => false,
        ], $schemas['ReactedInput'] ?? null);
    }
}
