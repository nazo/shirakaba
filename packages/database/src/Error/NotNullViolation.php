<?php

declare(strict_types=1);

namespace Shirakaba\Database\Error;

/**
 * A NOT NULL column received no value.
 */
final class NotNullViolation extends DatabaseError {}
