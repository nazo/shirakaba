<?php

declare(strict_types=1);

namespace Shirakaba\Core\Routing;

/**
 * @internal
 */
enum MatchStatus
{
    case Found;
    case MethodNotAllowed;
    case AutomaticOptions;
    case NotFound;
    case InvalidPath;
}
