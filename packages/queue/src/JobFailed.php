<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use RuntimeException;

/**
 * Thrown by a job handler when the job cannot succeed however often it runs, such as a mail the
 * server refused for good: the job is failed at once instead of being retried.
 */
final class JobFailed extends RuntimeException {}
