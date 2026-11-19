<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Attribute;

use Attribute;

/**
 * An integer incremented by every update(); an update of a stale entity fails with StaleEntity (optimistic locking).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Version {}
