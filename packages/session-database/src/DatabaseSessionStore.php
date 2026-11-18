<?php

declare(strict_types=1);

namespace Shirakaba\SessionDatabase;

use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Database;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Writer;
use Shirakaba\DatabaseBridge\RequestDatabase;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;
use Shirakaba\Session\JsonData;
use Shirakaba\Session\SessionStore;
use Shirakaba\Time\SystemClock;

/**
 * Keeps sessions in the application's database, so every instance behind a load balancer sees
 * the same session and no instance needs a writable file system:
 *
 *     $app->middleware(new SessionMiddleware(new DatabaseSessionStore($database)));
 *
 * Rows are keyed by the SHA-256 hash of the session ID, never the ID itself, so whoever reads
 * the table (a backup, a replica, an injected query) cannot take over a session. Data is JSON,
 * never PHP-serialised. The store uses the request's connection to the primary
 * (RequestDatabase), outside any transaction, so a request that rolls back still keeps its
 * session. Create the table with CreateSessionsTable and call prune() from a scheduled job.
 */
final readonly class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        private Database $database,
        private ClockInterface $clock = new SystemClock(),
        private string $table = 'shirakaba_sessions',
    ) {}

    /**
     * @return array<string, mixed>|null
     * @throws DatabaseError
     */
    public function load(RequestScope $scope, string $id): ?array
    {
        $row = Select::from($this->table)
            ->columns('data', 'expires_at')
            ->where('id_hash', '=', self::hash($id))
            ->where('expires_at', '>', $this->clock->now())
            ->fetchOne(RequestDatabase::reader($this->database, $scope));
        $data = $row['data'] ?? null;

        return is_string($data) ? JsonData::decode($data) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @throws LogicException inside a transaction, whose rollback would lose the session
     * @throws DatabaseError
     */
    public function save(RequestScope $scope, string $id, array $data, int $lifetime): void
    {
        $values = ['data' => JsonData::encode($data), 'expires_at' => $this->clock->now()->modify(sprintf('+%d seconds', $lifetime))];
        Insert::into($this->table)
            ->values(['id_hash' => self::hash($id), ...$values])
            ->onConflict(['id_hash'], ['data', 'expires_at'])
            ->execute($this->writer($scope));
    }

    /**
     * @throws LogicException inside a transaction
     * @throws DatabaseError
     */
    public function delete(RequestScope $scope, string $id): void
    {
        Delete::from($this->table)->where('id_hash', '=', self::hash($id))->execute($this->writer($scope));
    }

    /**
     * Deletes sessions that expired before now; run it from a scheduled job.
     *
     * @return int rows deleted
     * @throws DatabaseError
     */
    public function prune(Writer $db): int
    {
        return Delete::from($this->table)->where('expires_at', '<=', $this->clock->now())->execute($db);
    }

    /**
     * @throws LogicException inside a transaction
     */
    private function writer(RequestScope $scope): Writer
    {
        if (RequestDatabase::connection($this->database, $scope)->inTransaction()) {
            throw new LogicException('Sessions are saved outside transactions: a rollback would lose the session. Close the transaction before the request ends.');
        }

        return RequestDatabase::writer($this->database, $scope);
    }

    private static function hash(string $id): string
    {
        return hash('sha256', $id);
    }
}
