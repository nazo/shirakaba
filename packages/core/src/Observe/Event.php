<?php

declare(strict_types=1);

namespace Shirakaba\Core\Observe;

/**
 * Something a package reports during a request through RequestScope::record(), such as a
 * database statement. Observers can summarise events they do not know by name,
 * count and duration, or handle known classes specially.
 */
interface Event
{
    /**
     * A stable, dotted name such as "db.statement"; observers group by it.
     */
    public function name(): string;

    /**
     * How long the reported work took, in seconds, or null when it has no duration.
     */
    public function duration(): ?float;

    /**
     * Details safe to log and trace: never parameter values or secrets.
     *
     * @return array<string, string|int|float|bool>
     */
    public function attributes(): array;
}
