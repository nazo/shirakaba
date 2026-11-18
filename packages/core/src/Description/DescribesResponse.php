<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

/**
 * Implemented by response value classes that can say what they produce without an instance.
 */
interface DescribesResponse
{
    public static function describeResponse(): ResponseDescription;
}
