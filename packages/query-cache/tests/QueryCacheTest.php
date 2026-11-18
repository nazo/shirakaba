<?php

declare(strict_types=1);

namespace Shirakaba\QueryCache\Tests;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Observe\DatabaseEvent;
use Shirakaba\Database\Observe\EventSink;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Query\Update;
use Shirakaba\QueryCache\CacheLookup;
use Shirakaba\QueryCache\QueryCache;
use Shirakaba\QueryCache\Testing\ArrayCache;

#[CoversClass(QueryCache::class)]
#[CoversClass(CacheLookup::class)]
#[CoversClass(ArrayCache::class)]
final class QueryCacheTest extends TestCase
{
    private Connection $connection;
    private QueryCache $cache;

    protected function setUp(): void
    {
        $this->connection = new Database(static fn (): PDO => new PDO('sqlite::memory:'))->connect();
        $db = $this->connection->writer();
        $db->execute('CREATE TABLE countries (code TEXT PRIMARY KEY, name TEXT NOT NULL)');
        Insert::into('countries')->rows([['code' => 'JP', 'name' => 'Japan'], ['code' => 'FR', 'name' => 'France']])->execute($db);
        $this->cache = new QueryCache(new ArrayCache(), 'test');
    }

    private function statements(): int
    {
        return $this->connection->statistics()[0];
    }

    /**
     * @return list<string>
     */
    private function names(Reader $db): array
    {
        $rows = $this->cache->fetchAll(Select::from('countries')->columns('name')->orderBy('code'), $db, ttl: 60);

        return array_map(static fn (array $row): string => is_string($row['name'] ?? null) ? $row['name'] : '?', $rows);
    }

    public function testRepeatedReadsHitTheCacheUntilAWriteCommits(): void
    {
        $db = $this->connection->writer();

        self::assertSame(['France', 'Japan'], $this->names($db));
        $before = $this->statements();
        self::assertSame(['France', 'Japan'], $this->names($db));
        self::assertSame($before, $this->statements(), 'The second read comes from the cache.');

        $this->cache->execute(Update::table('countries')->set(['name' => 'Nippon'])->where('code', '=', 'JP'), $db);
        self::assertSame(['France', 'Nippon'], $this->names($db), 'A write invalidates the table.');
    }

    public function testInvalidationWaitsForCommitAndIsDroppedOnRollback(): void
    {
        $db = $this->connection->writer();
        $this->names($db);

        try {
            $db->transaction(function (Writer $db): void {
                $this->cache->execute(Update::table('countries')->set(['name' => 'Nippon'])->where('code', '=', 'JP'), $db);

                throw new LogicException('rolled back');
            });
        } catch (LogicException) {
        }
        $before = $this->statements();
        self::assertSame(['France', 'Japan'], $this->names($db));
        self::assertSame($before, $this->statements(), 'A rolled-back write invalidates nothing.');

        $db->transaction(function (Writer $db): void {
            $this->cache->execute(Update::table('countries')->set(['name' => 'Nippon'])->where('code', '=', 'JP'), $db);
            self::assertSame(['France', 'Japan'], $this->names($db), 'Inside the transaction the old entry stays.');
        });
        self::assertSame(['France', 'Nippon'], $this->names($db));
    }

    public function testCustomTagsAndTables(): void
    {
        $db = $this->connection->writer();
        $query = Select::from('countries', 'c')->join('countries', 'd.code', '=', 'c.code', 'd')->columns('c.name');

        self::assertSame(['countries'], $query->tables());
        $this->cache->fetchAll($query, $db, ttl: 60, tags: ['geo']);
        $before = $this->statements();
        $this->cache->invalidate($db, 'geo');
        $this->cache->fetchAll($query, $db, ttl: 60, tags: ['geo']);
        self::assertSame($before + 1, $this->statements());
    }

    public function testNamespacesKeepDatabasesApart(): void
    {
        $shared = new ArrayCache();
        $first = new QueryCache($shared, 'first');
        $second = new QueryCache($shared, 'second');
        $other = new Database(static fn (): PDO => new PDO('sqlite::memory:'))->connect()->writer();
        $other->execute('CREATE TABLE countries (code TEXT PRIMARY KEY, name TEXT NOT NULL)');
        Insert::into('countries')->values(['code' => 'DE', 'name' => 'Germany'])->execute($other);
        $query = Select::from('countries')->columns('name');

        self::assertCount(2, $first->fetchAll($query, $this->connection->writer(), ttl: 60));
        self::assertSame([['name' => 'Germany']], $second->fetchAll($query, $other, ttl: 60), 'The same SQL against another database is not served from the first.');
    }

    public function testRejectsEmptyNamespaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QueryCache(new ArrayCache(), '');
    }

    public function testReportsHitsAndMissesToWhoeverLeasedTheConnection(): void
    {
        $sink = new class implements EventSink {
            /** @var list<string> */
            public array $names = [];

            public function record(DatabaseEvent $event): void
            {
                if ($event instanceof CacheLookup) {
                    $this->names[] = $event->name();
                }
            }
        };
        $database = new Database(static fn (): PDO => new PDO('sqlite::memory:'));
        $lease = $database->lease($sink);
        $db = $lease->connection->writer();
        $db->execute('CREATE TABLE countries (code TEXT PRIMARY KEY, name TEXT NOT NULL)');
        $this->cache->fetchAll(Select::from('countries'), $db, ttl: 60);
        $this->cache->fetchAll(Select::from('countries'), $db, ttl: 60);
        $lease->release();

        self::assertSame(['db.cache.miss', 'db.cache.hit'], $sink->names);
    }
}
