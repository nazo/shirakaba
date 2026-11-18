<?php

declare(strict_types=1);

namespace Shirakaba\Tools\Monorepo;

use RuntimeException;

/**
 * Raised when a manifest cannot be loaded at all, so validation cannot continue.
 */
final class ValidationFailure extends RuntimeException {}
