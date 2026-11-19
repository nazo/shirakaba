<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use LogicException;

/**
 * An entity, relation or projection is declared wrongly; a programming error, raised when
 * the mapping is planned.
 */
final class MappingError extends LogicException {}
