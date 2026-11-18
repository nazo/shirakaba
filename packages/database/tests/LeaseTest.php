<?php

declare(strict_types=1);

namespace Shirakaba\Database\Tests;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Connection;
use Shirakaba\Database\ConnectionLeased;
use Shirakaba\Database\Database;
use Shirakaba\Database\Lease;
use Shirakaba\Database\Observe\DatabaseEvent;
use Shirakaba\Database\Observe\EventSink;
use Shirakaba\Database\StatementExecuted;

/**
 * Leases without the framework (design/08-database.md): what a script, worker or other framework does.
 */
#[CoversClass(Database::class)]
#[CoversClass(Lease::class)]
#[CoversClass(Connection::class)]
#[CoversClass(StatementExecuted::class)]
#[CoversClass(ConnectionLeased::class)]
final class LeaseTest extends TestCase
{
    public function testReportsTheLeaseAndEachStatementToTheSink(): void
    {
        $sink = new class implements EventSink {
            /** @var list<DatabaseEvent> */
            public array $events = [];

            public function record(DatabaseEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $lease = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->lease($sink);
        $lease->connection->reader()->fetchValue('SELECT 1');
        $lease->release();

        self::assertCount(2, $sink->events);
        self::assertInstanceOf(ConnectionLeased::class, $sink->events[0]);
        self::assertFalse($sink->events[0]->reused);
        self::assertInstanceOf(StatementExecuted::class, $sink->events[1]);
        self::assertSame('SELECT 1', $sink->events[1]->sql);
        self::assertSame(['db.system.name' => 'sqlite', 'db.query.text' => 'SELECT 1'], $sink->events[1]->attributes());
    }

    public function testAReleasedLeaseRefusesStatementsAndRollsBack(): void
    {
        $database = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'), reuse: true);
        $lease = $database->lease();
        $writer = $lease->connection->writer();
        $writer->execute('CREATE TABLE notes (body TEXT)');
        $lease->connection->pdo->beginTransaction();
        $writer->execute("INSERT INTO notes VALUES ('left open')");
        $lease->release();
        $refused = null;
        try {
            $writer->execute("INSERT INTO notes VALUES ('too late')");
        } catch (\Throwable $e) {
            $refused = $e;
        }
        self::assertInstanceOf(LogicException::class, $refused, 'A released connection must refuse statements.');
        self::assertStringContainsString('released with its lease', $refused->getMessage());
        self::assertTrue($lease->isReleased());
        $lease->release();

        $next = $database->lease();
        self::assertSame($lease->connection->pdo, $next->connection->pdo, 'The pool gives the connection to the next lease.');
        self::assertSame(0, $next->connection->reader()->fetchValue('SELECT COUNT(*) FROM notes'), 'The open transaction was rolled back.');
        $next->release();
    }

    public function testAConnectionOpenedWithConnectIsTheCallers(): void
    {
        $connection = new Database(static fn (): PDO => PDO::connect('sqlite::memory:'))->connect();
        $connection->release();

        self::assertSame(1, $connection->reader()->fetchValue('SELECT 1'), 'Only leased connections refuse statements after release.');
    }
}
