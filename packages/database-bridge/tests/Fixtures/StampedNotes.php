<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests\Fixtures;

use Shirakaba\Database\Reader;
use Psr\Clock\ClockInterface;

/**
 * A #[Db] class with an application service besides its Reader.
 */
final readonly class StampedNotes
{
    public function __construct(
        private ClockInterface $clock,
        private Reader $db,
        private ?\Countable $optional = null,
    ) {}

    public function summary(): string
    {
        return sprintf('%s at %s%s', var_export($this->db->fetchValue('SELECT COUNT(*) FROM notes'), true), $this->clock->now()->format('Y-m-d'), $this->optional === null ? '' : ' with extra');
    }
}
