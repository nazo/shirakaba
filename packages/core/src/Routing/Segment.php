<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

/**
 * One segment of a path template: static text, a parameter or a final catch-all.
 *
 * @internal
 */
final readonly class Segment
{
    private function __construct(
        public SegmentKind $kind,
        /** Static text, or the parameter name. */
        public string $value,
    ) {}

    public static function static(string $text): self
    {
        return new self(SegmentKind::Static, $text);
    }

    public static function parameter(string $name): self
    {
        return new self(SegmentKind::Parameter, $name);
    }

    public static function catchAll(string $name): self
    {
        return new self(SegmentKind::CatchAll, $name);
    }
}
