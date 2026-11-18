<?php

declare(strict_types=1);

namespace Shirakaba\Session;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * A Set-Cookie header with secure defaults: Secure, HttpOnly, SameSite=Lax, Path=/.
 * Names starting with "__Host-" are checked against the prefix's requirements.
 */
final readonly class SetCookie
{
    /**
     * @param 'Strict'|'Lax'|'None' $sameSite
     * @param int|null $maxAge seconds; null for a session cookie, 0 to delete
     * @throws InvalidArgumentException when the name, value or attributes are invalid
     */
    public function __construct(
        public string $name,
        public string $value,
        public ?int $maxAge = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = true,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid cookie name "%s".', $name));
        }
        if (preg_match('/[\x00-\x1F\x7F;]/', $path . ($domain ?? '')) === 1) {
            throw new InvalidArgumentException('Cookie path and domain must not contain control characters or ";".');
        }
        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('SameSite=None requires Secure.');
        }
        if (str_starts_with($name, '__Host-') && (!$secure || $path !== '/' || $domain !== null)) {
            throw new InvalidArgumentException('A "__Host-" cookie must be Secure, have Path=/ and no Domain.');
        }
    }

    public static function delete(string $name, string $path = '/', ?string $domain = null): self
    {
        return new self($name, '', 0, $path, $domain);
    }

    public function header(): string
    {
        $header = $this->name . '=' . rawurlencode($this->value);
        if ($this->maxAge !== null) {
            $header .= '; Max-Age=' . max(0, $this->maxAge);
        }
        $header .= '; Path=' . $this->path;
        if ($this->domain !== null) {
            $header .= '; Domain=' . $this->domain;
        }
        if ($this->secure) {
            $header .= '; Secure';
        }
        if ($this->httpOnly) {
            $header .= '; HttpOnly';
        }

        return $header . '; SameSite=' . $this->sameSite;
    }

    public function addTo(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header());
    }
}
