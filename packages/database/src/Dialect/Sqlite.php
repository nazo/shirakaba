<?php

declare(strict_types=1);

namespace Shirakaba\Database\Dialect;

use DateTimeImmutable;
use PDO;
use PDOException;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Error\ForeignKeyViolation;
use Shirakaba\Database\Error\NotNullViolation;
use Shirakaba\Database\Error\TransientFailure;
use Shirakaba\Database\Error\UniqueViolation;

/**
 * SQLite 3.35+ (RETURNING and upserts).
 */
final readonly class Sqlite implements Dialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function configure(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Off by default in SQLite, so references would silently go unchecked.
        $pdo->exec('PRAGMA foreign_keys = ON');
        // Wait for a lock instead of failing at once when another process writes.
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public function reset(PDO $pdo): void
    {
        $this->configure($pdo);
    }

    /**
     * SQLite has no statement time limit; queries run to completion.
     *
     * @return array{string, string}|null
     */
    public function statementTimeout(int $milliseconds): ?array
    {
        return null;
    }

    public function sessionSetting(): ?string
    {
        return null;
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
        return false;
    }

    public function limit(?int $limit, ?int $offset): string
    {
        return Quoting::limit($limit, $offset, '-1');
    }

    /**
     * @param list<string> $conflictColumns
     * @param array<string, string> $updateColumns
     */
    public function upsert(string $table, array $conflictColumns, array $updateColumns): string
    {
        $target = $conflictColumns === [] ? '' : ' (' . implode(', ', $conflictColumns) . ')';
        if ($updateColumns === []) {
            return sprintf('ON CONFLICT%s DO NOTHING', $target);
        }
        if ($target === '') {
            throw new \InvalidArgumentException('An SQLite upsert that updates needs the conflicting columns.');
        }

        return sprintf('ON CONFLICT%s DO UPDATE SET %s', $target, self::assignments($updateColumns));
    }

    public function formatInstant(DateTimeImmutable $instant): string
    {
        return Instants::utc($instant, 'Y-m-d\\TH:i:s.u\\Z');
    }

    public function parseInstant(string $value): ?DateTimeImmutable
    {
        return Instants::parse($value);
    }

    public function classify(PDOException $exception): ?DatabaseError
    {
        $code = $exception->errorInfo[1] ?? null;
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'UNIQUE constraint failed'), str_contains($message, 'PRIMARY KEY constraint failed') => new UniqueViolation($exception),
            str_contains($message, 'FOREIGN KEY constraint failed') => new ForeignKeyViolation($exception),
            str_contains($message, 'NOT NULL constraint failed') => new NotNullViolation($exception),
            // SQLITE_BUSY and SQLITE_LOCKED.
            $code === 5, $code === 6 => new TransientFailure($exception),
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
