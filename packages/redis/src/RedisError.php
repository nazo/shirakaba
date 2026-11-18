<?php

declare(strict_types=1);

namespace Shirakaba\Redis;

use RuntimeException;

/**
 * Redis could not be reached, refused a command, or answered something unexpected.
 */
final class RedisError extends RuntimeException {}
