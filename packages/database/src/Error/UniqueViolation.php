<?php

declare(strict_types=1);

namespace Shirakaba\Database\Error;

/**
 * A unique or primary key constraint rejected the statement.
 */
final class UniqueViolation extends DatabaseError {}
