<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Description\Property;
use Shirakaba\Core\Description\ScalarType;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\Tests\Fixtures\Address;
use Shirakaba\Json\Tests\Fixtures\CreateUser;
use Shirakaba\Json\Tests\Fixtures\Role;
use Shirakaba\Json\Tests\Fixtures\Secret;
use Shirakaba\Validation\Pattern;

#[CoversClass(Json::class)]
final class JsonTest extends TestCase
{
    public function testEncodesData(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Json => new Json(['a/b' => 'ü', 'role' => Role::Admin, 'n' => 1.0, 'list' => [1, 2]], 201, ['X-Id' => '1']));
        $response = new TestClient($app->build())->get('/');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Id'));
        self::assertSame('{"a/b":"ü","role":"admin","n":1.0,"list":[1,2]}', (string) $response->getBody());
    }

    public function testEncodesJsonDtosFromTheirConstructorFields(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Json => new Json(new \Shirakaba\Json\Tests\Fixtures\Card('Ada', Role::Admin, [new \Shirakaba\Json\Tests\Fixtures\Address(
            'Tokyo',
            '100-0001',
        )])));

        self::assertSame(
            '{"display_name":"Ada","role":"admin","addresses":[{"city":"Tokyo","zip":"100-0001"}]}',
            (string) new TestClient($app->build())->get('/')->getBody(),
        );
    }

    public function testJsonDtoNeedsPromotedPublicFields(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Json => new Json(new \Shirakaba\Json\Tests\Fixtures\LeakyDto('x', 'hunter2')));
        $response = new TestClient($app->build())->get('/');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', (string) $response->getBody());
    }

    public function testStatusDefaultsToTheDeclaredSuccessStatus(): void
    {
        $app = new AppBuilder();
        $app->post(
            '/one',
            #[\Shirakaba\Json\JsonResponse(201, Address::class)] static fn (): Json => new Json(new \Shirakaba\Json\Tests\Fixtures\Card('a', Role::Member)),
        );
        $app->post('/two', #[\Shirakaba\Core\Http\Responds(200)]
        #[\Shirakaba\Core\Http\Responds(202)] static fn (): Json => new Json(null));
        $app->post('/none', static fn (): Json => new Json(null));
        $client = new TestClient($app->build());

        self::assertSame(201, $client->post('/one')->getStatusCode());
        self::assertSame(200, $client->post('/two')->getStatusCode(), 'With several declared statuses, 200 stays the default.');
        self::assertSame(200, $client->post('/none')->getStatusCode());
    }

    public function testRefusesObjectsThatAreNotJsonSerializable(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Json => new Json(['user' => ['secret' => new Secret()]]));
        $response = new TestClient($app->build())->get('/');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', (string) $response->getBody());
    }

    public function testInvalidUtf8IsAnError(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Json => new Json("\xff"));

        self::assertSame(500, new TestClient($app->build())->get('/')->getStatusCode());
    }

    public function testJsonNameRenamesFieldsInRequestsAndDescriptions(): void
    {
        $app = new AppBuilder();
        $app->post(
            '/',
            #[\Shirakaba\Json\JsonResponse(201, \Shirakaba\Json\Tests\Fixtures\Paging::class, list: true)]
            static fn (#[JsonBody] \Shirakaba\Json\Tests\Fixtures\Paging $input): Json => new Json([$input->perPage], 201),
        );
        $client = new TestClient($app->build());

        self::assertSame('[5]', (string) $client->post('/', '{"per_page":5}', ['Content-Type' => 'application/json'])->getBody());
        self::assertSame(400, $client->post('/', '{"perPage":5}', ['Content-Type' => 'application/json'])->getStatusCode());

        $declared = ($app->describe()->routes[0] ?? null)?->declaredResponses[0] ?? null;
        self::assertNotNull($declared);
        self::assertSame([201, 'application/json'], [$declared->status, $declared->contentType]);
        $list = $declared->body;
        self::assertInstanceOf(ListType::class, $list);
        self::assertInstanceOf(ObjectType::class, $list->items);
        self::assertSame('per_page', ($list->items->properties[0] ?? null)?->name);
    }

    public function testDescribesBodiesWithTheMappingPlan(): void
    {
        $app = new AppBuilder();
        $app->post('/users', static fn (#[JsonBody] CreateUser $input): Json => new Json(null));
        $route = $app->describe()->routes[0] ?? null;
        self::assertNotNull($route);
        $body = $route->parameters[0] ?? null;
        self::assertNotNull($body);

        self::assertSame(ParameterDescription::BODY, $body->source);
        self::assertSame(['application/json'], $body->mediaTypes);
        self::assertSame([Json::class], $route->responses);
        $type = $body->type;
        self::assertInstanceOf(ObjectType::class, $type);
        self::assertSame(CreateUser::class, $type->class);
        self::assertSame(
            ['name', 'email', 'address', 'age', 'role', 'tags', 'previous', 'score'],
            array_map(static fn (Property $property): string => $property->name, $type->properties),
        );

        $name = self::property($type, 'name');
        $address = self::property($type, 'address');
        $age = self::property($type, 'age');
        $role = self::property($type, 'role');
        $tags = self::property($type, 'tags');
        self::assertTrue($name->required);
        self::assertFalse($age->required);
        self::assertEquals(new ScalarType('int', true), $age->type);
        self::assertSame('member', $role->default);
        self::assertEquals(new ListType(new ScalarType('string')), $tags->type);
        self::assertInstanceOf(ObjectType::class, $address->type);
        $zip = $address->type->properties[1] ?? null;
        self::assertNotNull($zip);
        self::assertEquals([new Pattern('^[0-9]{3}-[0-9]{4}$', 'must look like 123-4567')], $zip->constraints);
        self::assertSame(Address::class, $address->type->class);
    }

    private static function property(ObjectType $type, string $name): Property
    {
        foreach ($type->properties as $property) {
            if ($property->name === $name) {
                return $property;
            }
        }

        self::fail(sprintf('No property "%s".', $name));
    }
}
