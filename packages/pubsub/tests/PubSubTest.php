<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Database\Database;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;
use Shirakaba\PubSub\InMemoryPubSub;
use Shirakaba\PubSub\Internal\Channels;
use Shirakaba\PubSub\Message;
use Shirakaba\PubSub\PostgresPubSub;
use Shirakaba\PubSub\Subscription;

#[CoversClass(InMemoryPubSub::class)]
#[CoversClass(PostgresPubSub::class)]
#[CoversClass(Subscription::class)]
#[CoversClass(Channels::class)]
final class PubSubTest extends TestCase
{
    /**
     * @param \Closure(RequestScope): void $work
     */
    private static function inScope(\Closure $work, ?Cancellation $cancellation = null): void
    {
        $error = ScopedWork::run([], 'TEST', 'pubsub', $cancellation ?? new Cancellation(), $work);
        if ($error !== null) {
            throw $error;
        }
    }

    private static function writer(): Writer
    {
        return new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect()->writer();
    }

    public function testDeliversOnCommitAndDropsOnRollback(): void
    {
        $pubsub = new InMemoryPubSub();
        $db = self::writer();
        self::inScope(static function (RequestScope $scope) use ($pubsub, $db): void {
            $notes = $pubsub->subscribe($scope, ['notes', 'mentions']);
            $other = $pubsub->subscribe($scope, 'other');

            $db->transaction(static function (Writer $db) use ($pubsub, $notes): void {
                $pubsub->publish($db, 'notes', 'n1');
                self::assertNull($notes->next(), 'Nothing is delivered before commit.');
            });
            try {
                $db->transaction(static function (Writer $db) use ($pubsub): void {
                    $pubsub->publish($db, 'notes', 'rolled back');
                    throw new \RuntimeException('abort');
                });
            } catch (\RuntimeException) {
            }
            $pubsub->publish($db, 'mentions', 'm1');

            self::assertEquals(new Message('notes', 'n1'), $notes->next());
            self::assertEquals(new Message('mentions', 'm1'), $notes->next());
            self::assertNull($notes->next());
            self::assertNull($other->next());
            self::assertSame(2, $pubsub->subscriptions());
        });

        self::assertSame(0, $pubsub->subscriptions(), 'Subscriptions end with the request scope.');
    }

    public function testMessagesYieldHeartbeatsAndStopOnCancellation(): void
    {
        $pubsub = new InMemoryPubSub();
        $db = self::writer();
        $cancellation = new Cancellation();
        self::inScope(static function (RequestScope $scope) use ($pubsub, $db, $cancellation): void {
            $subscription = $pubsub->subscribe($scope, 'notes');
            $pubsub->publish($db, 'notes', 'n1');
            $seen = [];
            foreach ($subscription->messages(0.0) as $message) {
                $seen[] = $message?->payload;
                if (count($seen) === 2) {
                    $cancellation->request();
                }
            }

            self::assertSame(['n1', null], $seen);
        }, $cancellation);
    }

    public function testMessagesStopAtTheDeadline(): void
    {
        $pubsub = new InMemoryPubSub();
        $db = self::writer();
        $clock = new \Shirakaba\Time\FrozenClock('2026-11-12T00:00:00Z');
        self::inScope(static function (RequestScope $scope) use ($pubsub, $db, $clock): void {
            $subscription = $pubsub->subscribe($scope, 'notes');
            $pubsub->publish($db, 'notes', 'n1');
            $seen = [];
            foreach ($subscription->messages(15.0, new \DateTimeImmutable('2026-11-12T00:05:00Z'), $clock) as $message) {
                $seen[] = $message?->payload;
                $clock->advance(150);
            }

            self::assertSame(['n1', null], $seen, 'A message, a heartbeat, then the deadline.');
            self::assertSame([], iterator_to_array($subscription->messages(15.0, new \DateTimeImmutable('2026-11-12T00:00:00Z'), $clock)));
        });
    }

    public function testRejectsChannelNamesThatAreNotIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryPubSub()->publish(self::writer(), 'notes; DROP TABLE x', '');
    }

    public function testPostgresDeliversAtCommitThroughListenAndNotify(): void
    {
        $database = TestDatabases::available()['pgsql'][0] ?? null;
        if ($database === null) {
            self::markTestSkipped('Set SHIRAKABA_TEST_PGSQL_DSN to run against PostgreSQL.');
        }
        $pubsub = new PostgresPubSub($database);
        $publisher = $database->connect()->writer();

        self::inScope(static function (RequestScope $scope) use ($pubsub, $publisher, $database): void {
            $subscription = $pubsub->subscribe($scope, ['shirakaba_notes', 'Mixed_Case']);
            $before = RequestDatabase::connection($database, $scope)->statistics()[0];

            $publisher->transaction(static function (Writer $db) use ($pubsub, $subscription): void {
                $pubsub->publish($db, 'shirakaba_notes', '{"id":1}');
                self::assertNull($subscription->next(0.2), 'PostgreSQL holds the notification until commit.');
            });
            $pubsub->publish($publisher, 'Mixed_Case', 'case kept');

            self::assertEquals(new Message('shirakaba_notes', '{"id":1}'), $subscription->next(2.0));
            self::assertEquals(new Message('Mixed_Case', 'case kept'), $subscription->next(2.0));
            self::assertNull($subscription->next(0.1));
            self::assertSame($before, RequestDatabase::connection($database, $scope)->statistics()[0], 'Receiving runs no statements.');
        });

        // The scope ran UNLISTEN *: a new message on the same channel reaches nobody, and a
        // fresh scope on a fresh connection starts with no subscriptions.
        self::inScope(static function (RequestScope $scope) use ($pubsub, $publisher): void {
            $subscription = $pubsub->subscribe($scope, 'shirakaba_other');
            $pubsub->publish($publisher, 'shirakaba_notes', 'unheard');
            self::assertNull($subscription->next(0.2));
        });
    }
}
