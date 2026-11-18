<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Carries the matched route's decoded parameters on the request, as an attribute.
 *
 * @internal
 */
final readonly class RouteParameters
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public array $values,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function of(ServerRequestInterface $request): array
    {
        $parameters = $request->getAttribute(self::class);

        return $parameters instanceof self ? $parameters->values : [];
    }
}
