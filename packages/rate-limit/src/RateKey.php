<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Auth\Authentication;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\TrustedProxy\ClientAddress;

/**
 * What a route limit counts by (design/09-security.md). A key of null skips the limit for that request,
 * for example an anonymous request to a limit keyed by subject.
 */
final readonly class RateKey
{
    /**
     * @param Closure(ServerRequestInterface, RequestScope): ?string $key
     */
    private function __construct(
        public Closure $key,
    ) {}

    /**
     * The client's address: ClientAddress when TrustedProxies runs, otherwise REMOTE_ADDR.
     */
    public static function address(): self
    {
        return new self(static function (ServerRequestInterface $request): ?string {
            $address = ClientAddress::of($request) ?? $request->getServerParams()['REMOTE_ADDR'] ?? null;

            return is_string($address) && $address !== '' ? $address : null;
        });
    }

    /**
     * The authenticated principal (shirakaba/auth), named by $id; anonymous requests are not limited.
     *
     * @param Closure(object): (string|int) $id such as `fn (User $user) => $user->id`
     */
    public static function subject(Closure $id): self
    {
        return new self(static function (ServerRequestInterface $request, RequestScope $scope) use ($id): ?string {
            $authentication = $scope->find(Authentication::class);

            return $authentication === null ? null : (string) $id($authentication->principal);
        });
    }

    /**
     * Any key, such as the host of an HTTP signature's keyId.
     *
     * @param Closure(ServerRequestInterface, RequestScope): ?string $key
     */
    public static function custom(Closure $key): self
    {
        return new self($key);
    }
}
