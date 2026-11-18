<?php

declare(strict_types=1);

namespace Shirakaba\PubSub;

use LogicException;
use PDO;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\PubSub\Internal\Channels;
use Shirakaba\PubSub\Internal\Receiver;
use Shirakaba\DatabaseBridge\RequestDatabase;

/**
 * Publish/subscribe on PostgreSQL LISTEN/NOTIFY (design/11-real-time-events.md). Publishing runs pg_notify() on
 * the writer, so PostgreSQL itself delivers the message at commit. Subscribing runs LISTEN
 * on the request's connection (both appear in the statement log) and UNLISTEN when the
 * request scope closes. Payloads are limited to just under 8000 bytes by PostgreSQL; send
 * an ID and read the rest from the database.
 */
final readonly class PostgresPubSub implements Publisher, Subscriber
{
    public function __construct(
        private Database $database,
    ) {}

    public function publish(Writer $db, string $channel, string $payload): void
    {
        [$channel] = Channels::check($channel);
        self::requirePostgres($db->dialect()->name());
        $db->fetchValue('SELECT pg_notify(?, ?)', [$channel, $payload]);
    }

    /**
     * @param non-empty-list<string>|string $channels
     */
    public function subscribe(RequestScope $scope, string|array $channels): Subscription
    {
        $channels = Channels::check($channels);
        $connection = RequestDatabase::connection($this->database, $scope);
        self::requirePostgres($connection->dialect->name());
        $pdo = $connection->pdo;
        if (!$pdo instanceof \Pdo\Pgsql) {
            throw new LogicException('PostgresPubSub needs a Pdo\Pgsql connection; open it with PDO::connect().');
        }
        $writer = $connection->writer();
        foreach ($channels as $channel) {
            $writer->execute('LISTEN ' . $connection->dialect->quoteIdentifier($channel));
        }
        $receiver = new class ($pdo, $connection) implements Receiver {
            private bool $listening = true;

            public function __construct(
                private readonly \Pdo\Pgsql $pdo,
                private readonly Connection $connection,
            ) {}

            public function receive(int $timeoutMilliseconds): ?Message
            {
                if (!$this->listening) {
                    return null;
                }
                $notification = $this->pdo->getNotify(PDO::FETCH_ASSOC, $timeoutMilliseconds);
                if (!is_array($notification)) {
                    return null;
                }
                $channel = $notification['message'] ?? '';
                $payload = $notification['payload'] ?? '';

                return new Message(is_string($channel) ? $channel : '', is_string($payload) ? $payload : '');
            }

            public function close(): void
            {
                if ($this->listening) {
                    $this->listening = false;
                    $this->connection->writer()->execute('UNLISTEN *');
                }
            }
        };
        $subscription = new Subscription($receiver, $scope->cancellation());
        $scope->defer($subscription->close(...));

        return $subscription;
    }

    private static function requirePostgres(string $dialect): void
    {
        if ($dialect !== 'pgsql') {
            throw new LogicException(sprintf('PostgresPubSub needs PostgreSQL, not %s.', $dialect));
        }
    }
}
