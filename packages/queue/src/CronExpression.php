<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A five-field cron expression (minute, hour, day of month, month, day of week) evaluated
 * in a time zone (design/10-background-work.md). Fields take `*`, numbers, ranges `a-b`, lists `a,b` and steps
 * `*\/n` or `a-b/n`; days of the week run 0-7 with 0 and 7 both Sunday. As in cron, when both
 * day fields are restricted a time matches if either does. `@hourly`, `@daily`, `@weekly`,
 * `@monthly` and `@yearly` are accepted.
 */
final readonly class CronExpression
{
    /** @var array<string, string> */
    private const array ALIASES = [
        '@hourly' => '0 * * * *',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@weekly' => '0 0 * * 0',
        '@monthly' => '0 0 1 * *',
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
    ];

    /** @var list<array{int, int}> */
    private const array RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /**
     * @param list<array<int, true>> $fields allowed values per field
     */
    private function __construct(
        public string $expression,
        private array $fields,
        private bool $dayOfMonthRestricted,
        private bool $dayOfWeekRestricted,
    ) {}

    /**
     * @throws InvalidArgumentException when the expression is not valid
     */
    public static function parse(string $expression): self
    {
        $normalized = self::ALIASES[strtolower(trim($expression))] ?? trim($expression);
        $parts = preg_split('/\s+/', $normalized);
        if ($parts === false || count($parts) !== 5) {
            throw new InvalidArgumentException(sprintf('"%s" is not a cron expression: it needs five fields (minute hour day month weekday).', $expression));
        }
        $fields = [];
        foreach ($parts as $index => $part) {
            $fields[] = self::field($part, ...self::RANGES[$index]) ?? throw new InvalidArgumentException(sprintf(
                '"%s" is not a cron expression: field %d ("%s") is invalid.',
                $expression,
                $index + 1,
                $part,
            ));
        }
        if (isset($fields[4][7])) {
            $fields[4][0] = true;
        }

        return new self($expression, $fields, $parts[2] !== '*', $parts[4] !== '*');
    }

    /**
     * Whether the minute of $time, read in $timezone, matches.
     */
    public function matches(DateTimeImmutable $time, DateTimeZone $timezone = new DateTimeZone('UTC')): bool
    {
        $local = $time->setTimezone($timezone);
        $minute = (int) $local->format('i');
        $hour = (int) $local->format('G');
        $day = (int) $local->format('j');
        $month = (int) $local->format('n');
        $weekday = (int) $local->format('w');
        if (!isset($this->fields[0][$minute], $this->fields[1][$hour], $this->fields[3][$month])) {
            return false;
        }
        $dayMatches = isset($this->fields[2][$day]);
        $weekdayMatches = isset($this->fields[4][$weekday]);

        return $this->dayOfMonthRestricted && $this->dayOfWeekRestricted
            ? $dayMatches || $weekdayMatches
            : $dayMatches && $weekdayMatches;
    }

    /**
     * The first matching minute after $after, within $days days, or null.
     */
    public function next(DateTimeImmutable $after, DateTimeZone $timezone = new DateTimeZone('UTC'), int $days = 366): ?DateTimeImmutable
    {
        $utc = $after->setTimezone(new DateTimeZone('UTC'));
        $time = $utc->setTime((int) $utc->format('G'), (int) $utc->format('i'))->modify('+1 minute');
        for ($i = 0; $i < ($days * 1440); $i++) {
            if ($this->matches($time, $timezone)) {
                return $time;
            }
            $time = $time->modify('+1 minute');
        }

        return null;
    }

    /**
     * @return array<int, true>|null
     */
    private static function field(string $field, int $min, int $max): ?array
    {
        $values = [];
        foreach (explode(',', $field) as $item) {
            $parts = explode('/', $item, 2);
            $range = $parts[0];
            $step = 1;
            if (isset($parts[1])) {
                if (!ctype_digit($parts[1])) {
                    return null;
                }
                $step = (int) $parts[1];
            }
            if ($range === '*') {
                [$from, $to] = [$min, $max];
            } else {
                $bounds = explode('-', $range, 2);
                if (!ctype_digit($bounds[0]) || isset($bounds[1]) && !ctype_digit($bounds[1])) {
                    return null;
                }
                $from = (int) $bounds[0];
                $to = isset($bounds[1]) ? (int) $bounds[1] : ($step > 1 ? $max : $from);
            }
            if ($step < 1 || $from < $min || $to > $max || $from > $to) {
                return null;
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }
}
