<?php

declare(strict_types=1);

namespace Demo;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class AmbientClock
{
    public function reads(): void
    {
        time();
        microtime(true);
        date('Y-m-d');
        strtotime('+1 day');
        new DateTimeImmutable();
        new \DateTime('now');
        date_create_immutable();
        new DateTimeImmutable('tomorrow');
    }

    public function allowed(int $timestamp): void
    {
        date('Y-m-d', $timestamp);
        strtotime('2026-01-01', $timestamp);
        new DateTimeImmutable('@' . $timestamp);
        new DateTimeImmutable('2026-11-11T00:00:00Z');
        hrtime(true);
    }
}

final class RealClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
