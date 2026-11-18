<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use RuntimeException;

/**
 * Entities::get() found no row. The message names the entity only by its #[Table] label,
 * never by its class, so it may be shown to clients; shirakaba/database-bridge answers it with
 * a 404 (design/08-database.md).
 */
final class EntityNotFound extends RuntimeException
{
    public static function of(?string $label): self
    {
        return new self(sprintf('The %s was not found.', $label ?? 'requested resource'));
    }
}
