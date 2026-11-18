<?php

declare(strict_types=1);

namespace Shirakaba\MailerQueue;

use Shirakaba\Json\JsonDto;
use Shirakaba\Mailer\Address;

/**
 * An Address as it travels in a SendEmail job.
 */
#[JsonDto]
final readonly class QueuedAddress
{
    public function __construct(
        public string $address,
        public string $name = '',
    ) {}

    public static function of(Address $address): self
    {
        return new self($address->address, $address->name);
    }

    public function toAddress(): Address
    {
        return new Address($this->address, $this->name);
    }
}
