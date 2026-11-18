<?php

declare(strict_types=1);

namespace Shirakaba\Database\Dialect;

use DateTimeImmutable;
use PDO;
use PDOException;
use Shirakaba\Database\Error\ConnectionLost;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Error\ForeignKeyViolation;
use Shirakaba\Database\Error\NotNullViolation;
use Shirakaba\Database\Error\QueryTimedOut;
use Shirakaba\Database\Error\TransientFailure;
use Shirakaba\Database\Error\UniqueViolation;

/**
 * PostgreSQL 13+.
 */
final readonly class PostgreSql implements Dialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function configure(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec("SET client_encoding TO 'UTF8'");
    }

    public function reset(PDO $pdo): void
    {
        // Session settings, prepared statements, LISTEN, temporary tables, advisory locks.
        $pdo->exec('DISCARD ALL');
        $this->configure($pdo);
    }

    /**
     * @return array{string, string}
     */
    public function statementTimeout(int $milliseconds): array
    {
        // Inside a transaction the SET is undone with it if the statement is cancelled.
        return [sprintf('SET statement_timeout = %d', $milliseconds), 'SET statement_timeout = DEFAULT'];
    }

    public function sessionSetting(): string
    {
        return 'SELECT set_config(?, ?, false)';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return Quoting::quote($identifier, '"');
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsLocking(): bool
    {
        return true;
    }

    public function limit(?int $limit, ?int $offset): string
    {
        return Quoting::limit($limit, $offset, 'ALL');
    }

    /**
     * @param list<string> $conflictColumns
     * @param array<string, string> $updateColumns
     */
    public function upsert(string $table, array $conflictColumns, array $updateColumns): string
    {
        if ($conflictColumns === []) {
            throw new \InvalidArgumentException('A PostgreSQL upsert needs the conflicting columns.');
        }
        $target = '(' . implode(', ', $conflictColumns) . ')';
        if ($updateColumns === []) {
            return sprintf('ON CONFLICT %s DO NOTHING', $target);
        }

        return sprintf('ON CONFLICT %s DO UPDATE SET %s', $target, self::assignments($updateColumns));
    }

    public function formatInstant(DateTimeImmutable $instant): string
    {
        return Instants::utc($instant, 'Y-m-d H:i:s.uP');
    }

    public function parseInstant(string $value): ?DateTimeImmutable
    {
        return Instants::parse($value);
    }

    public function classify(PDOException $exception): ?DatabaseError
    {
        $state = $exception->errorInfo[0] ?? null;
        $state = is_string($state) ? $state : (string) $exception->getCode();

        return match (true) {
            $state === '23505' => new UniqueViolation($exception),
            $state === '23503' => new ForeignKeyViolation($exception),
            $state === '23502' => new NotNullViolation($exception),
            $state === '40001', $state === '40P01', $state === '55P03' => new TransientFailure($exception),
            $state === '57014' => new QueryTimedOut($exception),
            str_starts_with($state, '08'), $state === '57P01' => new ConnectionLost($exception),
            default => null,
        };
    }

    /**
     * @param array<string, string> $assignments quoted column => expression
     */
    private static function assignments(array $assignments): string
    {
        $sql = [];
        foreach ($assignments as $column => $expression) {
            $sql[] = $column . ' = ' . $expression;
        }

        return implode(', ', $sql);
    }
}
