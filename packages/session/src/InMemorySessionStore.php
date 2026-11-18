<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Keeps sessions in process memory: for tests, or a single worker process.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, array{data: string, expires: int}> */
    private array $sessions = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function load(RequestScope $scope, string $id): ?array
    {
        $session = $this->sessions[$id] ?? null;
        if ($session === null || $session['expires'] <= $this->clock->now()->getTimestamp()) {
            unset($this->sessions[$id]);

            return null;
        }

        return JsonData::decode($session['data']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(RequestScope $scope, string $id, array $data, int $lifetime): void
    {
        $this->sessions[$id] = ['data' => JsonData::encode($data), 'expires' => $this->clock->now()->getTimestamp() + $lifetime];
    }

    public function delete(RequestScope $scope, string $id): void
    {
        unset($this->sessions[$id]);
    }
}
