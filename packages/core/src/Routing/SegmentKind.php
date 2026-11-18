<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

/**
 * Segment kinds in precedence order: a lower rank wins when two templates match (design/04-routing-and-handlers.md).
 *
 * @internal
 */
enum SegmentKind: int
{
    case Static = 0;
    case Parameter = 1;
    case CatchAll = 2;
}
