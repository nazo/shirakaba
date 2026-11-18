<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ApplicationDescription;
use Shirakaba\Core\Description\EnumType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\RouteDescription;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Extraction\Header;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\NoContent;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Internal\HandlerPlan;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\RouteGroup;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Core\Tests\Fixtures\AtLeast;
use Shirakaba\Core\Tests\Fixtures\Color;
use Shirakaba\Core\Tests\Fixtures\OpaqueExtractor;
use Shirakaba\Core\Tests\Fixtures\Priority;
use Shirakaba\Core\Tests\Fixtures\Slug;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The metadata API of design/04-routing-and-handlers.md and constraints on built-in extractors.
 */
#[CoversClass(ApplicationDescription::class)]
#[CoversClass(RouteDescription::class)]
#[CoversClass(ParameterDescription::class)]
#[CoversClass(HandlerPlan::class)]
#[CoversClass(AppBuilder::class)]
final class DescriptionTest extends TestCase
{
    private static function app(): AppBuilder
    {
        $app = new AppBuilder();
        $app->get('/users/{id}', static fn (
            ServerRequestInterface $request,
            #[Path] #[AtLeast(1)] int $id,
            #[Query] bool $verbose = false,
            #[Header('X-Color')] ?Color $color = null,
            #[Query] Priority $priority = Priority::Normal,
        ): Text|NoContent => $verbose ? new Text('user ' . $id) : new NoContent())->name('users.show');
        $app->group('/admin', static function (RouteGroup $admin): void {
            $admin->route(['PUT', 'PATCH'], '/settings', static fn (#[OpaqueExtractor] string $value, RequestScope $scope): NoContent => new NoContent());
        });

        return $app;
    }

    public function testDescribesRoutesInDeclarationOrder(): void
    {
        $routes = self::app()->describe()->routes;

        self::assertSame(
            [['GET', '/users/{id}', 'users.show'], ['PUT', '/admin/settings', null], ['PATCH', '/admin/settings', null]],
            array_map(static fn (RouteDescription $route): array => [$route->method, $route->template, $route->name], $routes),
        );
    }

    public function testDescribesBuiltInParameters(): void
    {
        $route = self::app()->describe()->routes[0] ?? null;
        self::assertNotNull($route);

        self::assertSame(['id'], $route->templateParameters);
        self::assertSame([Text::class, NoContent::class], $route->responses);
        self::assertSame([], $route->undescribed);
        self::assertCount(4, $route->parameters, 'Injected parameters are not described.');

        [$id, $verbose, $color, $priority] = $route->parameters;
        self::assertEquals(new ParameterDescription('path', 'id', 'id', new ScalarType('int'), true, constraints: [new AtLeast(1)]), $id);
        self::assertEquals(new ParameterDescription('query', 'verbose', 'verbose', new ScalarType('bool'), false, true, false), $verbose);
        self::assertEquals(new ParameterDescription('header', 'X-Color', 'color', new EnumType(Color::class, ['red', 'blue'], true), false, true, null), $color);
        self::assertEquals(new ParameterDescription('query', 'priority', 'priority', new EnumType(Priority::class, [1, 2]), false, true, 1), $priority);
    }

    public function testListsUndescribedParameters(): void
    {
        $route = self::app()->describe()->routes[1] ?? null;
        self::assertNotNull($route);

        self::assertSame(['value'], $route->undescribed);
        self::assertSame([], $route->parameters);
    }

    public function testDescribeFailsOnTheSameMistakesAsBuild(): void
    {
        $app = new AppBuilder();
        $app->get('/a/{id}', static fn (#[Path] int $id): Text => new Text(''));
        $app->get('/a/{name}', static fn (#[Path] string $name): Text => new Text(''));

        $this->expectException(InvalidApplication::class);
        $app->describe();
    }

    public function testRequestValuesAreParsedAndDescribed(): void
    {
        $app = new AppBuilder();
        $app->get('/posts/{slug}', static fn (#[Path] Slug $slug, #[Query] ?Slug $tag = null): Text => new Text($slug->value . ' ' . ($tag->value ?? '-')));
        $client = new TestClient($app->build());

        self::assertSame('hello-world -', (string) $client->get('/posts/hello-world')->getBody());
        self::assertSame('a b', (string) $client->get('/posts/a?tag=b')->getBody());
        $invalid = $client->get('/posts/Hello');
        self::assertSame(400, $invalid->getStatusCode());
        self::assertStringContainsString('expected a valid Slug', (string) $invalid->getBody());

        $route = $app->describe()->routes[0] ?? null;
        self::assertNotNull($route);
        self::assertEquals(new ParameterDescription('path', 'slug', 'slug', new ScalarType('string'), true, constraints: [new AtLeast(0)]), $route->parameters[0] ?? null);
        self::assertEquals(new ScalarType('string', true), ($route->parameters[1] ?? null)?->type);
    }

    public function testDatesAreDescribedWithFormats(): void
    {
        $app = new AppBuilder();
        $app->get('/days/{day}', static fn (#[Path] \Shirakaba\Time\LocalDate $day, #[Query] ?\DateTimeImmutable $since = null): Text => new Text(''));
        $route = $app->describe()->routes[0] ?? null;

        self::assertNotNull($route);
        self::assertEquals(new ScalarType('string', false, 'date'), ($route->parameters[0] ?? null)?->type);
        self::assertEquals(new ScalarType('string', true, 'date-time'), ($route->parameters[1] ?? null)?->type);
    }

    public function testDeclaredResponsesAreDescribedAndChecked(): void
    {
        $app = new AppBuilder();
        $app->post('/items', #[\Shirakaba\Core\Http\Responds(201)] #[\Shirakaba\Core\Http\Responds(409, 'Duplicate.')] static fn (#[Query] bool $wrong = false): Text => new Text('created', $wrong ? 200 : 201));

        $declared = ($app->describe()->routes[0] ?? null)?->declaredResponses;
        self::assertEquals([
            new \Shirakaba\Core\Description\ResponseDescription(201),
            new \Shirakaba\Core\Description\ResponseDescription(409, 'application/problem+json', description: 'Duplicate.'),
        ], $declared);

        $client = new TestClient($app->build());
        self::assertSame(201, $client->post('/items')->getStatusCode());
        if (ini_get('zend.assertions') !== '1') {
            self::markTestIncomplete('Set zend.assertions=1 to check that an undeclared success status is caught.');
        }
        self::assertSame(500, $client->post('/items?wrong=true')->getStatusCode(), 'An undeclared success status fails the assertion in development.');
    }

    public function testConstraintsApplyToBuiltInExtractors(): void
    {
        $app = new AppBuilder();
        $app->get('/items', static fn (#[Query] #[AtLeast(1)] int $page = 1): Text => new Text('page ' . $page));
        $client = new TestClient($app->build());

        self::assertSame('page 3', (string) $client->get('/items?page=3')->getBody());
        $response = $client->get('/items?page=0');
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Invalid query parameter \"page\": must be at least 1.', (string) $response->getBody());
        self::assertSame('page 1', (string) $client->get('/items')->getBody(), 'Defaults are not re-validated.');
    }
}
