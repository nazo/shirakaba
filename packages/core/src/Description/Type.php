<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * A neutral description of the values a parameter or property accepts (design/04-routing-and-handlers.md).
 */
interface Type
{
    /**
     * Whether null is accepted in addition to the described values.
     */
    public function isNullable(): bool;
}
