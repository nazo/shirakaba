<?php

declare(strict_types=1);

namespace Shirakaba\Form\Tests\Fixtures;

use DateTimeImmutable;
use Shirakaba\Time\LocalDate;

final readonly class Booking
{
    public function __construct(
        public LocalDate $day,
        public ?DateTimeImmutable $remindAt = null,
    ) {}
}
