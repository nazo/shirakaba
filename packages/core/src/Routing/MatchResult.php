<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

/**
 * Outcome of matching a request against the route table.
 *
 * @internal
 */
final readonly class MatchResult
{
    /**
     * @param array<string, string> $parameters
     * @param list<string> $allowedMethods
     */
    private function __construct(
        public MatchStatus $status,
        public ?Route $route = null,
        public array $parameters = [],
        public array $allowedMethods = [],
        /** True when a HEAD request is served by the GET route and its body must be dropped. */
        public bool $headFallback = false,
    ) {}

    /**
     * @param array<string, string> $parameters
     */
    public static function found(Route $route, array $parameters, bool $headFallback): self
    {
        return new self(MatchStatus::Found, $route, $parameters, headFallback: $headFallback);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(MatchStatus::MethodNotAllowed, allowedMethods: $allowedMethods);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function automaticOptions(array $allowedMethods): self
    {
        return new self(MatchStatus::AutomaticOptions, allowedMethods: $allowedMethods);
    }

    public static function notFound(): self
    {
        return new self(MatchStatus::NotFound);
    }

    public static function invalidPath(): self
    {
        return new self(MatchStatus::InvalidPath);
    }
}
