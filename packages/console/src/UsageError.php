<?php

declare(strict_types=1);

namespace Shirakaba\Console;

use RuntimeException;

/**
 * The command line was wrong: an unknown command or option, a missing argument, a value of
 * the wrong type. The console prints it with a hint and exits with 2. Commands may throw it
 * for their own usage checks.
 */
final class UsageError extends RuntimeException
{
}
