<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Time\SystemClock;

/**
 * Stores each session as a JSON file. Session IDs are validated before they become file
 * names, and data is JSON, never PHP-serialised, so a stolen store cannot inject objects.
 * Files are created readable by their owner only.
 *
 * Expired files are removed when read; call collectGarbage() periodically (from a
 * scheduled job, for example) to remove sessions that are never read again. Without it,
 * every visitor who gets a session leaves a file behind.
 */
final readonly class FileSessionStore implements SessionStore
{
    /**
     * @throws InvalidArgumentException when the directory does not exist
     */
    public function __construct(
        private string $directory,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('The session directory "%s" does not exist.', $directory));
        }
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-impure io
     */
    public function load(RequestScope $scope, string $id): ?array
    {
        $file = $this->file($id);
        $contents = is_file($file) ? @file_get_contents($file) : false;
        if ($contents === false) {
            return null;
        }
        $record = JsonData::decode($contents);
        $expires = $record['expires'] ?? null;
        $data = $record['data'] ?? null;
        if (!is_int($expires) || $expires <= $this->clock->now()->getTimestamp() || !is_array($data)) {
            @unlink($file);

            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @throws RuntimeException when the file cannot be written
     * @phpstan-impure io, nondet.random
     */
    public function save(RequestScope $scope, string $id, array $data, int $lifetime): void
    {
        $file = $this->file($id);
        $contents = JsonData::encode(['expires' => $this->clock->now()->getTimestamp() + $lifetime, 'data' => $data]);
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        // Mode "x" refuses an existing file; chmod() runs before any data is written.
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to create a session file.');
        }
        try {
            $saved = @chmod($temporary, 0600) && @fwrite($handle, $contents) === strlen($contents);
        } finally {
            fclose($handle);
        }
        if (!$saved || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to write the session file.');
        }
    }

    /**
     * @phpstan-impure io
     */
    public function delete(RequestScope $scope, string $id): void
    {
        @unlink($this->file($id));
    }

    /**
     * Removes expired sessions and temporary files left by interrupted writes.
     *
     * @return int the number of files removed
     * @phpstan-impure io
     */
    public function collectGarbage(): int
    {
        $now = $this->clock->now()->getTimestamp();
        $removed = 0;
        $files = glob($this->directory . '/sess_*');
        foreach ($files === false ? [] : $files as $file) {
            if (str_ends_with($file, '.tmp')) {
                $modified = @filemtime($file);
                $stale = $modified !== false && $modified < ($now - 3600);
            } else {
                $contents = @file_get_contents($file);
                $expires = $contents === false ? null : JsonData::decode($contents)['expires'] ?? null;
                $stale = $contents !== false && (!is_int($expires) || $expires <= $now);
            }
            if ($stale && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function file(string $id): string
    {
        if (!Session::isValidId($id)) {
            throw new InvalidArgumentException('Invalid session ID.');
        }

        return $this->directory . '/sess_' . $id . '.json';
    }
}
