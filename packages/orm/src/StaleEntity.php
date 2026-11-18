<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use RuntimeException;

/**
 * An update or delete of a #[Version]ed entity found the row changed (or gone) since it
 * was read. The message names the entity only by its #[Table] label, so it may be shown to
 * clients; shirakaba/database-bridge answers it with a 409 (design/08-database.md).
 */
final class StaleEntity extends RuntimeException
{
    public static function of(?string $label): self
    {
        return new self(sprintf('The %s was changed by someone else; reload it and try again.', $label ?? 'resource'));
    }
}
