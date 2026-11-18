<?php

declare(strict_types=1);

namespace Shirakaba\Database;

use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Error\DatabaseError;

/**
 * Read access. Handlers for safe methods (GET, HEAD) take a Reader; the PHPStan rule of
 * design/13-static-analysis-and-agents.md reports those that take a Writer. The split is a capability by type, not an
 * enforcement in SQL.
 *
 * Parameters may be scalars, null, DateTimeImmutable (written in UTC), LocalDate and
 * backed enums (design/07-date-and-time.md).
 */
class Reader
{
    /**
     * @internal created by Connection
     */
    final public function __construct(
        protected readonly Connection $connection,
        /** Seconds each statement may run; null for no limit. */
        protected readonly ?float $timeout = null,
    ) {}

    /**
     * The same access with a time limit for each statement: PostgreSQL cancels a
     * statement after $seconds (`statement_timeout`), MySQL a SELECT (`max_execution_time`),
     * and either raises QueryTimedOut. SQLite has no such limit and runs statements to
     * completion. To stay within the request's deadline:
     * `$db->withTimeout(min(2.0, $scope->deadline()?->remaining() ?? 2.0))`.
     *
     * @throws \InvalidArgumentException when $seconds is not positive
     */
    public function withTimeout(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('A statement time limit must be positive.');
        }

        return new static($this->connection, $seconds);
    }

    public function dialect(): Dialect
    {
        return $this->connection->dialect;
    }

    /**
     * Reports an event to whoever leased the connection, such as a cache hit.
     */
    public function record(\Shirakaba\Database\Observe\DatabaseEvent $event): void
    {
        $this->connection->record($event);
    }

    /**
     * @param array<string|int, scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters
     * @return list<array<string, mixed>>
     * @throws DatabaseError when the statement fails
     * @phpstan-impure io.db
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $rows = [];
        foreach ($this->connection->run($sql, $parameters, $this->timeout)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::stringKeys($row);
            }
        }

        return $rows;
    }

    /**
     * Streams rows one at a time, for results too large to hold in memory.
     *
     * @param array<string|int, scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters
     * @return \Generator<int, array<string, mixed>>
     * @throws DatabaseError when the statement fails
     * @phpstan-impure io.db
     */
    public function iterate(string $sql, array $parameters = []): \Generator
    {
        $statement = $this->connection->run($sql, $parameters, $this->timeout);
        while (is_array($row = $statement->fetch(\PDO::FETCH_ASSOC))) {
            yield self::stringKeys($row);
        }
        $statement->closeCursor();
    }

    /**
     * @param array<string|int, scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters
     * @return array<string, mixed>|null
     * @throws DatabaseError when the statement fails
     * @phpstan-impure io.db
     */
    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->connection->run($sql, $parameters, $this->timeout);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return is_array($row) ? self::stringKeys($row) : null;
    }

    /**
     * @param array<string|int, scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters
     * @throws DatabaseError when the statement fails
     * @phpstan-impure io.db
     */
    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $statement = $this->connection->run($sql, $parameters, $this->timeout);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value === false ? null : $value;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private static function stringKeys(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
