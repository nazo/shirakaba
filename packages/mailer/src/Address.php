<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use InvalidArgumentException;

/**
 * A mail address with an optional display name (design/12-mail-and-templates.md). Both parts are checked when the
 * value is built: no CR, LF or NUL anywhere, so no header can be injected through them; an
 * ASCII local part (SMTPUTF8 is not supported); a domain that is converted to ASCII with
 * IDNA when ext-intl is present and refused when it is not ASCII otherwise.
 */
final readonly class Address
{
    public string $address;

    /**
     * @throws InvalidArgumentException for an invalid address or name
     */
    public function __construct(
        string $address,
        public string $name = '',
    ) {
        if (preg_match('/[\r\n\0]/', $address . $name) === 1) {
            throw new InvalidArgumentException('Mail addresses and display names cannot contain line breaks or NUL.');
        }
        $at = strrpos($address, '@');
        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a mail address.', $address));
        }
        $local = substr($address, 0, $at);
        $domain = self::asciiDomain(substr($address, $at + 1), $address);
        if (strlen($local) > 64 || preg_match('/\A[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*\z/', $local) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" has a local part that is not a plain ASCII dot-atom; quoted and non-ASCII local parts are not supported.', $address));
        }
        $this->address = $local . '@' . $domain;
    }

    /**
     * The domain after "@", for Message-ID.
     */
    public function domain(): string
    {
        return substr($this->address, (int) strrpos($this->address, '@') + 1);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function asciiDomain(string $domain, string $address): string
    {
        if (preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new InvalidArgumentException(sprintf('"%s" has a non-ASCII domain, which needs ext-intl.', $address));
            }
            $converted = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($converted === false) {
                throw new InvalidArgumentException(sprintf('"%s" has an invalid domain.', $address));
            }
            $domain = $converted;
        }
        if (strlen($domain) > 253 || preg_match('/\A[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\z/', $domain) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" has an invalid domain.', $address));
        }

        return strtolower($domain);
    }
}
