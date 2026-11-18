<?php

declare(strict_types=1);

namespace Shirakaba\Database\Error;

use PDOException;
use RuntimeException;

/**
 * A database error, wrapping the driver's PDOException. Subclasses name the causes applications handle; anything else stays a plain DatabaseError.
 */
class DatabaseError extends RuntimeException
{
    final public function __construct(
        public readonly PDOException $driverError,
    ) {
        parent::__construct($driverError->getMessage(), 0, $driverError);
    }

    /**
     * The SQLSTATE code, such as "23505".
     */
    public function sqlState(): string
    {
        $state = $this->driverError->errorInfo[0] ?? null;

        return is_string($state) ? $state : (string) $this->driverError->getCode();
    }
}
