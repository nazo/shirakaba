<?php

declare(strict_types=1);

namespace Shirakaba\Query\Tests\Fixtures;

use DateTimeImmutable;
use Shirakaba\Time\LocalDate;

final readonly class TaskRow
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $done,
        public Status $status,
        public DateTimeImmutable $createdAt,
        public ?LocalDate $dueOn = null,
        public float $weight = 1.0,
    ) {}
}
