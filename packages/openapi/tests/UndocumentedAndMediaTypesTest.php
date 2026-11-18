<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Http\Responds;
use Shirakaba\Core\Http\Text;
use Shirakaba\Json\Json;
use Shirakaba\OpenApi\Generator;
use Shirakaba\OpenApi\Info;

#[CoversClass(Generator::class)]
#[CoversClass(Responds::class)]
final class UndocumentedAndMediaTypesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function paths(): array
    {
        $app = new AppBuilder();
        $app->get('/users/{name}', #[Responds(200, 'The actor.', contentType: 'application/activity+json; charset=utf-8')] static fn (#[Path] string $name): Json => new Json([]));
        $app->get('/robots.txt', #[Responds(200, contentType: 'text/plain')] static fn (): Text => new Text(''));
        $app->get('/.well-known/webfinger', static fn (): Json => new Json([]))->undocumented();
        $json = json_decode(new Generator(new Info('Paths', '1'))->generate($app->describe())->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['paths'] ?? null);

        /** @var array<string, mixed> */
        return $json['paths'];
    }

    public function testUndocumentedRoutesAreLeftOut(): void
    {
        $paths = array_keys(self::paths());
        sort($paths);
        self::assertSame(['/robots.txt', '/users/{name}'], $paths);
        self::assertArrayNotHasKey('/.well-known/webfinger', self::paths());
    }

    public function testRespondsNamesOtherMediaTypes(): void
    {
        $paths = self::paths();
        self::assertIsArray($paths['/users/{name}'] ?? null);
        self::assertIsArray($paths['/users/{name}']['get'] ?? null);
        self::assertIsArray($paths['/users/{name}']['get']['responses'] ?? null);
        self::assertSame(
            ['description' => 'The actor.', 'content' => ['application/activity+json; charset=utf-8' => ['schema' => ['description' => 'Any JSON value.']]]],
            $paths['/users/{name}']['get']['responses']['200'] ?? null,
        );
        self::assertIsArray($paths['/robots.txt'] ?? null);
        self::assertIsArray($paths['/robots.txt']['get'] ?? null);
        self::assertIsArray($paths['/robots.txt']['get']['responses'] ?? null);
        self::assertSame(
            ['description' => 'Successful response.', 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]],
            $paths['/robots.txt']['get']['responses']['200'] ?? null,
        );
    }
}
