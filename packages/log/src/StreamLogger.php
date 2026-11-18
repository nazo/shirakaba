<?php

declare(strict_types=1);

namespace Shirakaba\Log;

use InvalidArgumentException;
use Shirakaba\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * A minimal PSR-3 logger writing one JSON object per line, by default to standard error,
 * which container platforms and FrankenPHP collect. For anything more, use a full logging
 * library; LoggingObserver accepts any PSR-3 logger.
 */
final class StreamLogger extends AbstractLogger
{
    /**
     * @var array<string, int>
     */
    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream defaults to php://stderr
     * @throws InvalidArgumentException when the minimum level is unknown
     */
    public function __construct(
        $stream = null,
        private readonly string $minimumLevel = LogLevel::DEBUG,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if (!isset(self::LEVELS[$minimumLevel])) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $minimumLevel));
        }
        $opened = $stream ?? fopen('php://stderr', 'w');
        if (!is_resource($opened)) {
            throw new InvalidArgumentException('StreamLogger needs a writable stream.');
        }
        $this->stream = $opened;
    }

    /**
     * @param array<array-key, mixed> $context
     * @phpstan-impure io
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? strtolower($level) : '';
        if ((self::LEVELS[$level] ?? -1) < (self::LEVELS[$this->minimumLevel] ?? 0)) {
            return;
        }

        $record = [
            'time' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level,
            'message' => self::interpolate((string) $message, $context),
        ];
        foreach ($context as $key => $value) {
            $record['context'][(string) $key] = $value instanceof Throwable ? self::exception($value) : self::scalar($value);
        }

        fwrite($this->stream, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', static function (array $match) use ($context): string {
            $value = $context[$match[1]] ?? null;

            return match (true) {
                $value === null => $match[0],
                is_scalar($value), $value instanceof Stringable => (string) $value,
                default => '[' . get_debug_type($value) . ']',
            };
        }, $message) ?? $message;
    }

    /**
     * @return array<string, mixed>
     */
    private static function exception(Throwable $error): array
    {
        $data = ['class' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile() . ':' . $error->getLine(), 'trace' => $error->getTraceAsString()];
        $previous = $error->getPrevious();

        return $previous === null ? $data : [...$data, 'previous' => self::exception($previous)];
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof Stringable => (string) $value,
            is_array($value) => array_map(self::scalar(...), $value),
            default => '[' . get_debug_type($value) . ']',
        };
    }
}
