<?php

declare(strict_types=1);

namespace Shirakaba\TrustedProxy;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address of the client that sent the request, as determined by TrustedProxies.
 */
final readonly class ClientAddress
{
    public function __construct(
        public string $address,
    ) {}

    /**
     * The address recorded by TrustedProxies, or REMOTE_ADDR when the middleware did not run.
     */
    public static function of(ServerRequestInterface $request): ?string
    {
        $recorded = $request->getAttribute(self::class);
        if ($recorded instanceof self) {
            return $recorded->address;
        }
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : null;
    }
}
