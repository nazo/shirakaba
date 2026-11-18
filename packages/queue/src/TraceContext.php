<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

/**
 * The W3C traceparent of the active OpenTelemetry span, if the API is installed and a span
 * is active, so a job's trace continues the request that pushed it.
 *
 * @internal
 */
final readonly class TraceContext
{
    public static function current(): ?string
    {
        if (!class_exists(\OpenTelemetry\API\Trace\Span::class)) {
            return null;
        }
        $context = \OpenTelemetry\API\Trace\Span::getCurrent()->getContext();
        if (!$context->isValid()) {
            return null;
        }

        return sprintf('00-%s-%s-%s', $context->getTraceId(), $context->getSpanId(), $context->isSampled() ? '01' : '00');
    }
}
