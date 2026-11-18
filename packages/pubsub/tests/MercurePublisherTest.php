<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\Http\Message\HttpFactory;
use Shirakaba\PubSub\MercurePublisher;
use Shirakaba\PubSub\Tests\Fixtures\RecordingClient;
use RuntimeException;

#[CoversClass(MercurePublisher::class)]
final class MercurePublisherTest extends TestCase
{
    private static function publisher(RecordingClient $client, bool $private = false): MercurePublisher
    {
        $factory = new HttpFactory();

        return new MercurePublisher($client, $factory, $factory, 'http://hub.test/.well-known/mercure', 'jwt', 'https://example.com/streams/', $private);
    }

    private static function writer(): Writer
    {
        return new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer();
    }

    public function testPublishesAfterCommitOnly(): void
    {
        $client = new RecordingClient();
        $publisher = self::publisher($client);
        $db = self::writer();

        $db->transaction(static function (Writer $db) use ($publisher, $client): void {
            $publisher->publish($db, 'notes', '{"id":1}');
            self::assertSame([], $client->sent, 'Nothing is sent before commit.');
        });
        try {
            $db->transaction(static function (Writer $db) use ($publisher): void {
                $publisher->publish($db, 'notes', 'rolled back');
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        self::assertCount(1, $client->sent);
        $request = $client->sent[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('http://hub.test/.well-known/mercure', (string) $request->getUri());
        self::assertSame('Bearer jwt', $request->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $fields);
        self::assertSame(['topic' => 'https://example.com/streams/notes', 'data' => '{"id":1}'], $fields);
    }

    public function testPrivateUpdatesAndRefusals(): void
    {
        $client = new RecordingClient();
        self::publisher($client, private: true)->publish(self::writer(), 'notes', 'x');
        parse_str((string) ($client->sent[0] ?? self::fail('Nothing was sent.'))->getBody(), $fields);
        self::assertSame('on', $fields['private'] ?? null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refused the update with status 401');
        self::publisher(new RecordingClient(401))->publish(self::writer(), 'notes', 'x');
    }

    public function testOneUpdateForSeveralChannelsWithAnEventType(): void
    {
        $client = new RecordingClient();
        self::publisher($client)->publishTo(self::writer(), ['users_1', 'users_2'], '{"id":1}', private: true, event: 'note');

        self::assertSame(
            'topic=https%3A%2F%2Fexample.com%2Fstreams%2Fusers_1&topic=https%3A%2F%2Fexample.com%2Fstreams%2Fusers_2&data=%7B%22id%22%3A1%7D&private=on&type=note',
            (string) ($client->sent[0] ?? self::fail('Nothing was sent.'))->getBody(),
        );

        $this->expectException(\InvalidArgumentException::class);
        self::publisher($client)->publishTo(self::writer(), ['users_1'], 'x', event: 'mercure');
    }
}
