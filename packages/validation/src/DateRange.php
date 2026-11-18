<?php

declare(strict_types=1);

namespace Shirakaba\Validation;

use Attribute;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Shirakaba\Core\Extraction\Constraint;
use Shirakaba\Time\LocalDate;
use Shirakaba\Time\Rfc3339;

/**
 * Limits a LocalDate or a DateTimeImmutable to fixed, inclusive bounds:
 *
 *     #[DateRange(notBefore: '1900-01-01')] LocalDate $birthday
 *     #[DateRange(notAfter: '2030-12-31T23:59:59Z')] DateTimeImmutable $deadline
 *
 * Bounds are "YYYY-MM-DD" or RFC 3339 date-times. A date bound compared with an instant
 * means the start of that day in UTC (for notBefore) or its end (for notAfter).
 *
 * Rules relative to now ("in the future") are not attributes: attributes cannot receive a
 * clock. Check them where a ClockInterface is available, in the handler or a service, so
 * tests can freeze time (design/07-date-and-time.md).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class DateRange implements Constraint
{
    /**
     * @throws InvalidArgumentException when a bound is not a date or date-time, or the bounds are inverted
     */
    public function __construct(
        public ?string $notBefore = null,
        public ?string $notAfter = null,
    ) {
        foreach ([$notBefore, $notAfter] as $bound) {
            if ($bound !== null && LocalDate::parse($bound) === null && Rfc3339::parse($bound) === null) {
                throw new InvalidArgumentException(sprintf('"%s" is not a date (YYYY-MM-DD) or an RFC 3339 date-time.', $bound));
            }
        }
        if ($notBefore !== null && $notAfter !== null && self::instant($notBefore, false) > self::instant($notAfter, true)) {
            throw new InvalidArgumentException('notBefore must not be after notAfter.');
        }
    }

    public function violation(mixed $value): ?string
    {
        if ($value instanceof LocalDate) {
            $date = $value->toString();
            if ($this->notBefore !== null && $date < self::date($this->notBefore)) {
                return sprintf('must not be before %s', self::date($this->notBefore));
            }
            if ($this->notAfter !== null && $date > self::date($this->notAfter)) {
                return sprintf('must not be after %s', self::date($this->notAfter));
            }

            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            if ($this->notBefore !== null && $value < self::instant($this->notBefore, false)) {
                return sprintf('must not be before %s', $this->notBefore);
            }
            if ($this->notAfter !== null && $value > self::instant($this->notAfter, true)) {
                return sprintf('must not be after %s', $this->notAfter);
            }
        }

        return null;
    }

    /**
     * JSON Schema has no bounds for dates; formatMinimum and formatMaximum are understood
     * by common validators (ajv-formats) and harmless elsewhere.
     *
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return array_filter(['formatMinimum' => $this->notBefore, 'formatMaximum' => $this->notAfter], static fn (?string $bound): bool => $bound !== null);
    }

    private static function date(string $bound): string
    {
        $instant = Rfc3339::parse($bound);

        return $instant === null ? $bound : $instant->format('Y-m-d');
    }

    private static function instant(string $bound, bool $endOfDay): DateTimeImmutable
    {
        $instant = Rfc3339::parse($bound);
        if ($instant !== null) {
            return $instant;
        }
        $start = (LocalDate::parse($bound) ?? throw new InvalidArgumentException('Invalid bound.'))->atStartOfDay(new DateTimeZone('UTC'));

        return $endOfDay ? $start->modify('+1 day -1 microsecond') : $start;
    }
}
