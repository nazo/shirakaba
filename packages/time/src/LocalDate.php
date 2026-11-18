<?php

declare(strict_types=1);

namespace Shirakaba\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Stringable;

/**
 * A calendar date without a time or timezone, such as a birthday or a due date (design/07-date-and-time.md).
 * Its text form is ISO 8601 "YYYY-MM-DD", years 0001 to 9999.
 *
 * A DateTimeImmutable at midnight is not a date: shown in another timezone it falls on the
 * previous or next day. Convert explicitly with atStartOfDay() and fromInstant().
 */
final readonly class LocalDate implements Stringable
{
    private function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {}

    /**
     * @throws InvalidArgumentException when the date does not exist
     */
    public static function of(int $year, int $month, int $day): self
    {
        if ($year < 1 || $year > 9999 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(sprintf('%04d-%02d-%02d is not a valid date.', $year, $month, $day));
        }

        return new self($year, $month, $day);
    }

    /**
     * Parses "YYYY-MM-DD" strictly; anything else, including impossible dates, is null.
     */
    public static function parse(string $value): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];

        return $year >= 1 && checkdate($month, $day, $year) ? new self($year, $month, $day) : null;
    }

    /**
     * The date an instant falls on in a timezone.
     */
    public static function fromInstant(DateTimeInterface $instant, DateTimeZone $timezone): self
    {
        $local = DateTimeImmutable::createFromInterface($instant)->setTimezone($timezone);

        return new self((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
    }

    /**
     * Today in a timezone; there is no default, because "today" differs around the world.
     */
    public static function today(ClockInterface $clock, DateTimeZone $timezone): self
    {
        return self::fromInstant($clock->now(), $timezone);
    }

    /**
     * The first instant of this date in a timezone.
     */
    public function atStartOfDay(DateTimeZone $timezone): DateTimeImmutable
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $this->toString(), $timezone);
        if ($start === false) {
            throw new \LogicException(sprintf('Unable to build the start of %s.', $this->toString()));
        }

        return $start;
    }

    public function addDays(int $days): self
    {
        $shifted = $this->atStartOfDay(new DateTimeZone('UTC'))->modify(sprintf('%+d days', $days));

        return self::fromInstant($shifted, new DateTimeZone('UTC'));
    }

    /**
     * ISO 8601 weekday: 1 for Monday to 7 for Sunday.
     *
     * @return int<1, 7>
     */
    public function dayOfWeek(): int
    {
        $weekday = (int) $this->atStartOfDay(new DateTimeZone('UTC'))->format('N');

        return max(1, min(7, $weekday));
    }

    /**
     * @return int<-1, 1>
     */
    public function compare(self $other): int
    {
        return [$this->year, $this->month, $this->day] <=> [$other->year, $other->month, $other->day];
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isBefore(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
