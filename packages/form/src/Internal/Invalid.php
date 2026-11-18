<?php

declare(strict_types=1);

namespace Shirakaba\Form\Internal;

/**
 * Marks a form value that failed conversion, distinct from a legitimate null.
 *
 * @internal
 */
enum Invalid
{
    case Value;
}
