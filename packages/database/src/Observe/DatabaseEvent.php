<?php

declare(strict_types=1);

namespace Shirakaba\Database\Observe;

/**
 * Something the database packages report while they work, such as a statement, a
 * leased connection or a cache lookup. The same shape as the
 * framework's observer events, so shirakaba/database-bridge can hand them to request
 * observers without the database packages depending on the framework (design/08-database.md).
 */
interface DatabaseEvent
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
