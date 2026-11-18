<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use RuntimeException;
use Throwable;

/**
 * A mail could not be sent. $retryable says whether trying again later may succeed: SMTP 4xx
 * replies and lost connections may, 5xx replies and invalid mail may not.
 */
final class MailError extends RuntimeException
{
    public function __construct(
        string $message,
        /** The SMTP reply code, or null when the server did not answer. */
        public readonly ?int $smtpCode = null,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
