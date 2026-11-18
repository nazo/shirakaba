<?php

declare(strict_types=1);

namespace Shirakaba\Http\Message\Tests\Fixtures;

/**
 * In-memory stream wrapper whose files accept at most $capacity bytes in total.
 * Once full, writes return 0, which is how a full disk looks to PHP.
 *
 * Static state is acceptable here: the wrapper exists only in tests and is reset by
 * register().
 */
final class LimitedWriteWrapper
{
    public const string SCHEME = 'shirakaba-limited';

    /** @var array<string, string> */
    public static array $files = [];

    public static int $capacity = PHP_INT_MAX;

    /** @var resource|null set by PHP */
    public $context;

    private string $path = '';

    public static function register(int $capacity): void
    {
        self::unregister();
        self::$files = [];
        self::$capacity = $capacity;
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (str_starts_with($mode, 'x') && isset(self::$files[$path])) {
            return false;
        }
        $this->path = $path;
        self::$files[$path] = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $current = self::$files[$this->path] ?? '';
        $room = max(0, self::$capacity - strlen($current));
        $accepted = substr($data, 0, $room);
        self::$files[$this->path] = $current . $accepted;

        return strlen($accepted);
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$files[$this->path] ?? '')];
    }

    public function stream_close(): void {}

    public function rename(string $from, string $to): bool
    {
        if (!isset(self::$files[$from])) {
            return false;
        }
        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);

        return true;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }
}
