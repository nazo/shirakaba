<?php

declare(strict_types=1);

namespace Shirakaba\QueryCache;

use JsonException;
use Psr\SimpleCache\CacheInterface;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\RowMapper;
use Shirakaba\Query\Select;
use Shirakaba\Query\Update;

/**
 * Opt-in caching of query results. Nothing is cached unless a call asks:
 *
 *     $cache = new QueryCache($psr16Cache, 'shop-primary');    // application-scoped
 *     $rows = $cache->fetchAll(Select::from('countries'), $reader, ttl: 3600);
 *     $cache->execute(Update::table('countries')->set([...])->where(...), $writer);
 *
 * Each cached query is tagged with the tables it reads and any extra tags. Writing through
 * execute(), or calling invalidate(), retires a tag after the transaction commits; a
 * rollback retires nothing. Tags are versions stored in the cache, so invalidation works
 * across processes that share the cache.
 *
 * Rows are stored as JSON, never with serialize(), so a compromised cache cannot inject
 * objects. Rows that cannot be encoded (binary columns) are simply not cached.
 */
final readonly class QueryCache
{
    private string $prefix;

    /**
     * @param string $namespace identifies the database the cached queries read; two
     *                          applications or databases sharing one cache must use different
     *                          namespaces, or the same SQL would return the other's rows
     * @throws \InvalidArgumentException for an empty namespace
     */
    public function __construct(
        private CacheInterface $cache,
        string $namespace,
    ) {
        if ($namespace === '' || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('The query cache namespace must be 1 to 64 letters, digits, ".", "_" or "-".');
        }
        $this->prefix = 'shirakaba.query.' . $namespace . '.';
    }

    /**
     * @param int<1, max> $ttl seconds
     * @param list<string> $tags tags besides the tables the query reads
     * @return list<array<string, mixed>>
     * @throws DatabaseError
     */
    public function fetchAll(Select $query, Reader $db, int $ttl, array $tags = []): array
    {
        $tags = array_values(array_unique([...$query->tables(), ...$tags]));
        $compiled = $query->toSql($db->dialect());
        $key =
            $this->prefix
            . 'q.'
            . hash('xxh128', json_encode([
                $db->dialect()->name(),
                $compiled->sql,
                array_map(self::parameter(...), $compiled->parameters),
                $this->versions($tags),
            ], JSON_THROW_ON_ERROR));

        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            $rows = self::decode($cached);
            if ($rows !== null) {
                $db->record(new CacheLookup(true, $tags));

                return $rows;
            }
        }
        $db->record(new CacheLookup(false, $tags));
        $rows = $query->fetchAll($db);
        try {
            $this->cache->set($key, json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $ttl);
        } catch (JsonException) {
            // Not representable as JSON: serve uncached.
        }

        return $rows;
    }

    /**
     * Maps cached rows into $class (see RowMapper).
     *
     * @template T of object
     * @param class-string<T> $class
     * @param int<1, max> $ttl
     * @param list<string> $tags
     * @return list<T>
     * @throws DatabaseError
     */
    public function fetchInto(string $class, Select $query, Reader $db, int $ttl, array $tags = []): array
    {
        $mapper = RowMapper::for($class, $db->dialect());

        return array_map($mapper->map(...), $this->fetchAll($query, $db, $ttl, $tags));
    }

    /**
     * Runs a write and retires the cache entries of its table and $tags after commit.
     *
     * @param list<string> $tags
     * @return int affected rows
     * @throws DatabaseError
     */
    public function execute(Insert|Update|Delete $statement, Writer $db, array $tags = []): int
    {
        $affected = $statement->execute($db);
        $this->invalidate($db, $statement->tableName(), ...$tags);

        return $affected;
    }

    /**
     * Retires tags (table names or custom tags) once the current transaction commits, or at
     * once outside a transaction.
     */
    public function invalidate(Writer $db, string ...$tags): void
    {
        $tags = array_values($tags);
        $db->afterCommit(function () use ($tags): void {
            $values = [];
            foreach ($tags as $tag) {
                $values[$this->tagKey($tag)] = bin2hex(random_bytes(8));
            }
            $this->cache->setMultiple($values);
        });
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    private function versions(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $keys = array_map($this->tagKey(...), $tags);
        $versions = [];
        $missing = [];
        foreach ($this->cache->getMultiple($keys) as $key => $version) {
            if (is_string($version)) {
                $versions[(string) $key] = $version;
            } else {
                $missing[(string) $key] = bin2hex(random_bytes(8));
            }
        }
        if ($missing !== []) {
            // A tag seen for the first time gets a version, so a later invalidation changes it.
            $this->cache->setMultiple($missing);
        }
        $versions = [...$versions, ...$missing];
        ksort($versions);

        return $versions;
    }

    private function tagKey(string $tag): string
    {
        return $this->prefix . 't.' . hash('xxh128', $tag);
    }

    private static function parameter(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeImmutable => ['instant', $value->format('U.u')],
            $value instanceof \Shirakaba\Time\LocalDate => ['date', $value->toString()],
            $value instanceof \BackedEnum => ['enum', $value->value],
            default => $value,
        };
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function decode(string $json): ?array
    {
        try {
            $rows = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return null;
            }
            $clean = [];
            foreach ($row as $column => $value) {
                $clean[(string) $column] = $value;
            }
            $result[] = $clean;
        }

        return $result;
    }
}
