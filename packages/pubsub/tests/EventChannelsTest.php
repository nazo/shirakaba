<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Description\UnionType;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\Http\Message\HttpFactory;
use Shirakaba\PubSub\EventChannels;
use Shirakaba\PubSub\Internal\ChannelMap;
use Shirakaba\PubSub\Internal\Channels;
use Shirakaba\PubSub\MercureEvents;
use Shirakaba\PubSub\MercurePublisher;
use Shirakaba\PubSub\MercureTokens;
use Shirakaba\PubSub\Tests\Fixtures\Events\BoardChannel;
use Shirakaba\PubSub\Tests\Fixtures\Events\BoardUserChannel;
use Shirakaba\PubSub\Tests\Fixtures\Events\BrokenChannel;
use Shirakaba\PubSub\Tests\Fixtures\Events\Card;
use Shirakaba\PubSub\Tests\Fixtures\Events\CardAdded;
use Shirakaba\PubSub\Tests\Fixtures\Events\PhaseChanged;
use Shirakaba\PubSub\Tests\Fixtures\RecordingClient;

#[CoversClass(EventChannels::class)]
#[CoversClass(ChannelMap::class)]
#[CoversClass(Channels::class)]
#[CoversClass(MercureEvents::class)]
#[CoversClass(MercureTokens::class)]
final class EventChannelsTest extends TestCase
{
    private static function writer(): Writer
    {
        return new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer();
    }

    public function testBuildsTopicsWithEncodedParameters(): void
    {
        self::assertSame('board/a%2Fb%20c', EventChannels::topic(new BoardChannel('a/b c')));
        self::assertSame('board/01J9/user/42', EventChannels::topic(new BoardUserChannel('01J9', 42)));
    }

    public function testWritesTheTypeIntoThePayload(): void
    {
        self::assertSame(
            '{"type":"card.added","seq":3,"card":{"id":"c1","body":"白樺"}}',
            EventChannels::payload(new BoardChannel('b'), new CardAdded(3, new Card('c1', '白樺'))),
        );
    }

    public function testPublishesOneUpdateToEveryTopicWithoutAnEventName(): void
    {
        $client = new RecordingClient();
        $factory = new HttpFactory();
        $events = new MercureEvents(new MercurePublisher($client, $factory, $factory, 'http://hub.test/.well-known/mercure', 'jwt', 'https://example.com/streams/'));
        $db = self::writer();

        $events->publish($db, [new BoardUserChannel('b1', 1), new BoardUserChannel('b1', 2)], new CardAdded(1, new Card('c', 'x')));

        self::assertCount(1, $client->sent);
        $body = (string) $client->sent[0]->getBody();
        self::assertSame(
            'topic=' . rawurlencode('https://example.com/streams/board/b1/user/1') . '&topic=' . rawurlencode('https://example.com/streams/board/b1/user/2')
                . '&data=' . rawurlencode('{"type":"card.added","seq":1,"card":{"id":"c","body":"x"}}') . '&private=on',
            $body,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not declare an event of class ' . PhaseChanged::class);
        $events->publish($db, new BoardUserChannel('b1', 1), new PhaseChanged(2, 'write'));
    }

    public function testTokensNameTheSameTopics(): void
    {
        $tokens = new MercureTokens('https://example.com', 'https://example.com/.well-known/mercure', str_repeat('k', 32));
        $token = $tokens->channelSubscriber('user-1', 'https://example.com/streams/', new BoardChannel('a b'));
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/'), true), true);

        self::assertIsArray($claims);
        $details = $claims['authorization_details'] ?? null;
        self::assertIsArray($details);
        self::assertIsArray($details[0] ?? null);
        self::assertSame([['match' => 'https://example.com/streams/board/a%20b']], $details[0]['topics'] ?? null);
    }

    public function testDescribesChannelsAsTaggedUnions(): void
    {
        [$board, $user] = EventChannels::describe(BoardChannel::class, BoardUserChannel::class) + [null, null];

        self::assertNotNull($board);
        self::assertNotNull($user);
        self::assertSame(['board', 'board/{boardId}', true, 'Updates every participant of a board may see'], [$board->name, $board->channel, $board->private, $board->summary]);
        self::assertSame('boardUser', $user->name);
        self::assertSame(['boardId', 'userId'], array_keys($user->parameters));
        self::assertInstanceOf(UnionType::class, $board->payload);
        self::assertSame('BoardEvent', $board->payload->name);
        self::assertSame(['card.added', 'phase.changed'], array_keys($board->payload->members));
        $member = $board->payload->members['card.added'] ?? null;
        self::assertNotNull($member);
        $properties = $member->properties;
        self::assertSame('type', ($properties[0] ?? null)?->name);
    }

    public function testRefusesInvalidChannels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parameter $weight must be a promoted string, int or backed enum');
        EventChannels::describe(BrokenChannel::class);
    }

    public function testMercureChannelsMayBePathsButNotAnything(): void
    {
        self::assertSame(['board/a%2Fb/user/1'], Channels::checkTopics('board/a%2Fb/user/1'));
        foreach (['board//x', '/board', 'board/a b', 'board/%zz', "board\nx"] as $bad) {
            try {
                Channels::checkTopics($bad);
                self::fail(sprintf('"%s" must be refused.', $bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Channels::check('board/x');
    }
}
