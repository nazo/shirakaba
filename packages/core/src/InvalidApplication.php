<?php

declare(strict_types=1);

namespace Shirakaba\Core;

use LogicException;

/**
 * The application definition is invalid: a route, handler or extractor was set up wrongly.
 * Raised while building, never while handling a request.
 */
final class InvalidApplication extends LogicException {}
