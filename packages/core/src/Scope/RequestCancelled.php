<?php

declare(strict_types=1);

namespace Shirakaba\Core\Scope;

use RuntimeException;

/**
 * Thrown by Cancellation::throwIfRequested(). Treated as an unexpected error (500) if it
 * escapes, which normally nobody sees because the client is gone. Libraries bound to an
 * interface with its own exceptions may throw a subclass that also implements them, such as
 * the HTTP client's CallCancelled (a PSR-18 ClientExceptionInterface).
 */
class RequestCancelled extends RuntimeException {}
