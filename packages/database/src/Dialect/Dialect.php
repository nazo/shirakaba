<?php

declare(strict_types=1);

namespace Shirakaba\Database\Dialect;

use DateTimeImmutable;
use PDO;
use PDOException;
use Shirakaba\Database\Error\DatabaseError;

/**
 * What differs between MySQL, PostgreSQL and SQLite, in one place. The query
 * builder and the ORM ask the dialect instead of checking driver names.
 */
interface Dialect
{
    /**
     * The PDO driver name: "mysql", "pgsql" or "sqlite".
     */
    public function name(): string;

    /**
     * Applies safe session settings right after connecting: errors as exceptions, native
     * prepared statements, UTC, strict modes (design/07-date-and-time.md).
     */
    public function configure(PDO $pdo): void;

    /**
     * Returns a connection a request has finished with to the state configure() left it in,
     * so the next request can reuse it: session settings, LISTEN registrations,
     * temporary tables, prepared statements and session locks, where the database can.
     * Called outside any transaction.
     *
     * @throws \PDOException when the connection cannot be reset; it is then closed instead
     */
    public function reset(PDO $pdo): void;

    /**
     * The statements that set a time limit for the following statements, and that remove it
     * again, or null when the database cannot limit a statement's time.
     *
     * @param positive-int $milliseconds
     * @return array{string, string}|null
     */
    public function statementTimeout(int $milliseconds): ?array;

    /**
     * The statement that sets a custom session setting, taking the name and the value as its two
     * parameters, or null when the database has no such settings (design/08-database.md).
     */
    public function sessionSetting(): ?string;

    /**
     * Quotes an identifier such as a table or column name; "a.b" quotes both parts.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Whether INSERT, UPDATE and DELETE accept a RETURNING clause.
     */
    public function supportsReturning(): bool;

    /**
     * Whether SELECT ... FOR UPDATE locks rows.
     */
    public function supportsLocking(): bool;

    /**
     * The clause limiting a result, such as "LIMIT 10 OFFSET 20"; empty when neither is set.
     */
    public function limit(?int $limit, ?int $offset): string;

    /**
     * The clause turning an INSERT into an upsert.
     *
     * @param string $table the quoted table
     * @param list<string> $conflictColumns quoted columns of the unique key that may conflict
     * @param array<string, string> $updateColumns quoted column => the SQL it is set to, in which
     *                                            `excluded` names the inserted row; empty to
     *                                            keep the existing row
     */
    public function upsert(string $table, array $conflictColumns, array $updateColumns): string;

    /**
     * Writes an instant for a parameter, in UTC (design/07-date-and-time.md): "2026-11-11 00:30:00.000000" on
     * MySQL, with "+00:00" on PostgreSQL, "2026-11-11T00:30:00.000000Z" on SQLite, whose
     * text then sorts in time order.
     */
    public function formatInstant(DateTimeImmutable $instant): string;

    /**
     * Reads an instant from a column value as the driver returns it; null when it is not one.
     */
    public function parseInstant(string $value): ?DateTimeImmutable;

    /**
     * Classifies a driver exception: unique and foreign key violations, transient failures
     * worth retrying, lost connections; null for anything else.
     */
    public function classify(PDOException $exception): ?DatabaseError;
}
