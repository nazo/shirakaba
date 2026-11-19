<?php

declare(strict_types=1);

namespace Shirakaba\Database\Error;

/**
 * A statement ran longer than the time limit of its Reader and was cancelled by
 * the database. Handlers can answer with a 503 or a partial result.
 */
final class QueryTimedOut extends DatabaseError {}
