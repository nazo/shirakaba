<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use DateTimeImmutable;
use LogicException;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Database;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;

/**
 * Counts hits in a table shared by every process (design/09-security.md): one upsert per hit on the
 * request's connection, outside any transaction, so a failed request still counts. Create the
 * table with CreateRateLimitsTable and prune it with prune(), for example from a scheduled job.
 */
final readonly class DatabaseRateLimitStore implements RateLimitStore
{
    public function __construct(
        private Database $database,
        private string $table = 'shirakaba_rate_limits',
    ) {}

    /**
     * @throws LogicException inside a transaction, whose rollback would undo the hit
     */
    public function hit(RequestScope $scope, string $bucket, DateTimeImmutable $window, DateTimeImmutable $expiresAt): int
    {
        if (RequestDatabase::connection($this->database, $scope)->inTransaction()) {
            throw new LogicException('Rate limits are counted outside transactions: a rollback would undo the hit. Check the limit before opening one.');
        }
        $db = RequestDatabase::writer($this->database, $scope);
        $insert = Insert::into($this->table)
            ->values(['bucket' => $bucket, 'window_start' => $window, 'hits' => 1, 'expires_at' => $expiresAt])
            ->onConflict(['bucket', 'window_start'], ['hits' => Sql::raw($this->table . '.hits + 1')]);
        if ($db->dialect()->supportsReturning()) {
            $hits = $insert->returning('hits')->executeReturning($db)[0]['hits'] ?? 0;
        } else {
            $insert->execute($db);
            $hits = Select::from($this->table)->columns('hits')->where('bucket', '=', $bucket)->where('window_start', '=', $window)->fetchValue($db);
        }

        return is_numeric($hits) ? (int) $hits : 0;
    }

    /**
     * Deletes counts that expired before $now.
     *
     * @return int rows deleted
     */
    public function prune(Writer $db, DateTimeImmutable $now): int
    {
        return Delete::from($this->table)->where('expires_at', '<', $now)->execute($db);
    }
}
