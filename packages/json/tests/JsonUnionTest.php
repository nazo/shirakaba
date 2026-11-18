<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ConstType;
use Shirakaba\Core\Description\ListType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Description\UnionType;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Json\Internal\ObjectNode;
use Shirakaba\Json\Internal\Planner;
use Shirakaba\Json\Internal\UnionNode;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\JsonDto;
use Shirakaba\Json\JsonMapper;
use Shirakaba\Json\JsonResponse;
use Shirakaba\Json\JsonUnion;
use Shirakaba\Json\Tests\Fixtures\BadUnion;
use Shirakaba\Json\Tests\Fixtures\FollowNotification;
use Shirakaba\Json\Tests\Fixtures\Inbox;
use Shirakaba\Json\Tests\Fixtures\Notification;
use Shirakaba\Json\Tests\Fixtures\ReactionNotification;
use Shirakaba\Json\Tests\Fixtures\TaggedMember;
use UnexpectedValueException;

#[CoversClass(JsonUnion::class)]
#[CoversClass(UnionNode::class)]
#[CoversClass(ObjectNode::class)]
#[CoversClass(Planner::class)]
#[CoversClass(JsonDto::class)]
#[CoversClass(Json::class)]
#[CoversClass(JsonResponse::class)]
#[CoversClass(JsonMapper::class)]
final class JsonUnionTest extends TestCase
{
    public function testEncodesMembersWithTheirDiscriminatorFirst(): void
    {
        self::assertSame(
            '[{"type":"follow","id":"n1","userId":"u1"},{"type":"reaction","id":"n2","note":"x","reaction":"👍"}]',
            Json::encode([new FollowNotification('n1', 'u1'), new ReactionNotification('n2', 'x', '👍')]),
        );
    }

    public function testDescribesAListResponseAsAUnionOfOutputMembers(): void
    {
        $type = new JsonResponse(200, Notification::class, list: true)->responseDescription()->body;

        self::assertInstanceOf(ListType::class, $type);
        $union = $type->items;
        self::assertInstanceOf(UnionType::class, $union);
        self::assertSame('type', $union->discriminator);
        self::assertTrue($union->output);
        self::assertSame(['follow', 'reaction'], array_keys($union->members));
        $follow = $union->members['follow'] ?? null;
        self::assertInstanceOf(ObjectType::class, $follow);
        self::assertTrue($follow->output);
        self::assertSame(['type', 'id', 'userId'], array_map(static fn ($property): string => $property->name, $follow->properties));
        $tag = $follow->properties[0] ?? null;
        self::assertNotNull($tag);
        self::assertEquals(new ConstType('follow'), $tag->type);
        self::assertTrue($tag->required);
    }

    private static function client(): TestClient
    {
        $app = new AppBuilder();
        $app->post('/notifications', static fn (#[JsonBody] Notification $input): Json => new Json($input));

        return new TestClient($app->build());
    }

    public function testJsonBodyPicksTheMemberByItsDiscriminator(): void
    {
        $response = self::client()->post('/notifications', '{"id":"n2","type":"reaction","note":"x","reaction":"ok"}', ['Content-Type' => 'application/json']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"type":"reaction","id":"n2","note":"x","reaction":"ok"}', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{string, list<array{pointer: string, detail: string}>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no discriminator' => ['{"id":"n1"}', [['pointer' => '/type', 'detail' => 'is required']]];
        yield 'unknown discriminator' => ['{"type":"poke","id":"n1"}', [['pointer' => '/type', 'detail' => 'must be one of: "follow", "reaction"']]];
        yield 'discriminator not a string' => ['{"type":1,"id":"n1"}', [['pointer' => '/type', 'detail' => 'must be one of: "follow", "reaction"']]];
        yield 'member fields' => ['{"type":"follow","id":"n1","note":"x"}', [
            ['pointer' => '/userId', 'detail' => 'is required'],
            ['pointer' => '/note', 'detail' => 'is not an accepted field'],
        ]];
        yield 'member constraints' => ['{"type":"reaction","id":"n1","note":"x","reaction":""}', [['pointer' => '/reaction', 'detail' => 'must be at least 1 characters long']]];
        yield 'not an object' => ['[]', [['pointer' => '/', 'detail' => 'must be an object']]];
    }

    /**
     * @param list<array{pointer: string, detail: string}> $errors
     */
    #[DataProvider('invalidBodies')]
    public function testRejectsBodiesThatMatchNoMember(string $body, array $errors): void
    {
        $response = self::client()->post('/notifications', $body, ['Content-Type' => 'application/json']);
        $problem = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains($response->getStatusCode(), [400, 422]);
        self::assertIsArray($problem);
        self::assertSame($errors, self::withoutCodes($problem['errors'] ?? null));
    }

    public function testUnionsNestInListsAndNullableFieldsAndRoundTrip(): void
    {
        $inbox = new Inbox([new FollowNotification('n1', 'u1'), new ReactionNotification('n2', 'x', 'ok')], new FollowNotification('n3', 'u2'));
        $json = Json::encode($inbox);

        self::assertEquals($inbox, JsonMapper::decode($json, Inbox::class));
        self::assertEquals(new Inbox([]), JsonMapper::decode('{"items":[],"pinned":null}', Inbox::class));
        self::assertEquals(new FollowNotification('n1', 'u1'), JsonMapper::decode('{"type":"follow","id":"n1","userId":"u1"}', Notification::class));
    }

    public function testAMemberDecodedOnItsOwnChecksItsDiscriminator(): void
    {
        self::assertEquals(new FollowNotification('n1', 'u1'), JsonMapper::decode('{"type":"follow","id":"n1","userId":"u1"}', FollowNotification::class));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('/type must be "follow"');
        JsonMapper::decode('{"type":"reaction","id":"n1","userId":"u1"}', FollowNotification::class);
    }

    public function testRefusesAMemberThatDeclaresTheDiscriminator(): void
    {
        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage(TaggedMember::class . ' is a #[JsonUnion] member, so it must not declare the discriminator field "type"');
        new Planner(false, 'Test')->root(BadUnion::class);
    }

    public function testDescribesAMemberUsedAloneWithItsDiscriminator(): void
    {
        $type = new JsonResponse(200, ReactionNotification::class)->responseDescription()->body;

        self::assertInstanceOf(ObjectType::class, $type);
        self::assertEquals(new ConstType('reaction'), ($type->properties[0] ?? null)?->type);
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
