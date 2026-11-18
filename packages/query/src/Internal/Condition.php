<?php

declare(strict_types=1);

namespace Shirakaba\Query\Internal;

/**
 * @internal
 */
interface Condition
{
    public function compile(Output $out): string;
}
