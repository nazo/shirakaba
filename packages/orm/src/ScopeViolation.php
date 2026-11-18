<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use RuntimeException;

/**
 * An entity to insert lies outside a scope it was inserted with, such as a card
 * carrying another organization's id. Treat it as an attack or a bug, not as user input.
 */
final class ScopeViolation extends RuntimeException
{
    public static function of(string $entity, string $scope): self
    {
        return new self(sprintf('The %s to insert lies outside %s.', $entity, $scope));
    }
}
