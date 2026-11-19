<?php

declare(strict_types=1);

namespace Shirakaba\Json\Tests\Fixtures;

use DateTimeImmutable;
use Shirakaba\Json\JsonDto;
use Shirakaba\Json\ListOf;
use Shirakaba\Time\LocalDate;

#[JsonDto]
final readonly class Event
{
    /**
     * @param list<LocalDate> $holidays
     */
    public function __construct(
        public string $title,
        public DateTimeImmutable $startsAt,
        public LocalDate $day,
        public ?DateTimeImmutable $endsAt = null,
        #[ListOf(LocalDate::class)] public array $holidays = [],
    ) {}
}
