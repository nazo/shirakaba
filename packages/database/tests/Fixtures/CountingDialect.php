<?php

declare(strict_types=1);

namespace Shirakaba\Database\Tests\Fixtures;

use DateTimeImmutable;
use PDO;
use PDOException;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Dialect\Sqlite;
use Shirakaba\Database\Error\DatabaseError;

/**
 * SQLite with statement time limits that only count how often they are set, for checking
 * round trips. The SETs call PHP functions registered on the connection.
 */
final class CountingDialect implements Dialect
{
    /** @var list<int> limits set; 0 for a reset */
    public array $sets = [];

    /** @var list<string> session settings set, as "name=value" */
    public array $settings = [];

    private Sqlite $sqlite;

    public function __construct()
    {
        $this->sqlite = new Sqlite();
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function configure(PDO $pdo): void
    {
        $this->sqlite->configure($pdo);
        if ($pdo instanceof \Pdo\Sqlite) {
            $pdo->createFunction('shirakaba_set_limit', function (int $milliseconds): int {
                $this->sets[] = $milliseconds;

                return $milliseconds;
            }, 1);
            $pdo->createFunction('shirakaba_set_config', function (string $name, string $value): string {
                $this->settings[] = $name . '=' . $value;

                return $value;
            }, 2);
        }
    }

    public function reset(PDO $pdo): void
    {
        $this->sqlite->reset($pdo);
    }

    public function statementTimeout(int $milliseconds): array
    {
        return [sprintf('SELECT shirakaba_set_limit(%d)', $milliseconds), 'SELECT shirakaba_set_limit(0)'];
    }

    public function sessionSetting(): string
    {
        return 'SELECT shirakaba_set_config(?, ?)';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->sqlite->quoteIdentifier($identifier);
    }

    public function supportsReturning(): bool
    {
        return $this->sqlite->supportsReturning();
    }

    public function supportsLocking(): bool
    {
        return $this->sqlite->supportsLocking();
    }

    public function limit(?int $limit, ?int $offset): string
    {
        return $this->sqlite->limit($limit, $offset);
    }

    public function upsert(string $table, array $conflictColumns, array $updateColumns): string
    {
        return $this->sqlite->upsert($table, $conflictColumns, $updateColumns);
    }

    public function formatInstant(DateTimeImmutable $instant): string
    {
        return $this->sqlite->formatInstant($instant);
    }

    public function parseInstant(string $value): ?DateTimeImmutable
    {
        return $this->sqlite->parseInstant($value);
    }

    public function classify(PDOException $exception): ?DatabaseError
    {
        return $this->sqlite->classify($exception);
    }
}
