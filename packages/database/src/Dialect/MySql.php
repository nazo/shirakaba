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
 * MySQL 8.0+ (and compatible servers).
 */
final readonly class MySql implements Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function configure(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Native prepared statements: values never become part of the SQL text, and
        // integers come back as integers.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci, time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
    }

    /**
     * MySQL has no SQL statement that resets a session: named locks are released and Shirakaba's
     * settings applied again, but user variables and temporary tables a request created stay.
     */
    public function reset(PDO $pdo): void
    {
        $pdo->exec('DO RELEASE_ALL_LOCKS()');
        $pdo->exec('SET SESSION max_execution_time = DEFAULT');
        $this->configure($pdo);
    }

    /**
     * MySQL limits only SELECT statements (`max_execution_time`); writes run to completion.
     *
     * @return array{string, string}
     */
    public function statementTimeout(int $milliseconds): array
    {
        return [sprintf('SET SESSION max_execution_time = %d', $milliseconds), 'SET SESSION max_execution_time = DEFAULT'];
    }

    public function sessionSetting(): ?string
    {
        return null;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return Quoting::quote($identifier, '`');
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function supportsLocking(): bool
    {
        return true;
    }

    public function limit(?int $limit, ?int $offset): string
    {
        return Quoting::limit($limit, $offset, '18446744073709551615');
    }

    /**
     * @param list<string> $conflictColumns
     * @param array<string, string> $updateColumns
     */
    public function upsert(string $table, array $conflictColumns, array $updateColumns): string
    {
        // MySQL infers the conflicting key; an alias replaces the deprecated VALUES().
        if ($updateColumns === []) {
            $first = $conflictColumns[0] ?? throw new \InvalidArgumentException('An upsert needs a conflict or update column.');

            return sprintf('AS excluded ON DUPLICATE KEY UPDATE %s.%s = %s.%s', $table, $first, $table, $first);
        }

        return 'AS excluded ON DUPLICATE KEY UPDATE ' . self::assignments($updateColumns);
    }

    public function formatInstant(DateTimeImmutable $instant): string
    {
        return Instants::utc($instant, 'Y-m-d H:i:s.u');
    }

    public function parseInstant(string $value): ?DateTimeImmutable
    {
        return Instants::parse($value);
    }

    public function classify(PDOException $exception): ?DatabaseError
    {
        $code = $exception->errorInfo[1] ?? null;

        return match ($code) {
            1062 => new UniqueViolation($exception),
            1451, 1452 => new ForeignKeyViolation($exception),
            1048, 1364 => new NotNullViolation($exception),
            1205, 1213 => new TransientFailure($exception),
            3024 => new QueryTimedOut($exception),
            2002, 2006, 2013 => new ConnectionLost($exception),
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
