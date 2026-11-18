<?php

declare(strict_types=1);

namespace Shirakaba\Core\Observe;

/**
 * What observers learn about a request before routing.
 *
 * Deliberately excludes the path, query string, cookies and Authorization: they may hold
 * personal data or secrets, and raw paths make metric cardinality unbounded. The route
 * template arrives with RouteSummary once a route matches. Only the W3C trace propagation
 * headers are passed on, so tracing can continue a caller's trace.
 */
final readonly class RequestSummary
{
    /**
     * @var list<string>
     */
    public const array PROPAGATION_HEADERS = ['traceparent', 'tracestate', 'baggage'];

    /**
     * @param array<string, string> $propagation lower-cased propagation header => value
     */
    public function __construct(
        public string $method,
        public string $protocolVersion,
        public array $propagation = [],
    ) {}
}
