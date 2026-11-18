<?php

declare(strict_types=1);

namespace Shirakaba\Database\Error;

/**
 * A deadlock, serialisation failure or lock timeout; retrying the transaction may succeed.
 */
final class TransientFailure extends DatabaseError
{
}
