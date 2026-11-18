<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

use Shirakaba\Core\Extraction\HandlerParameter;
use Shirakaba\Core\InvalidApplication;

/**
 * Implemented by extractors that can describe the parameter they fill (design/04-routing-and-handlers.md).
 * Extractors without it are reported as undescribed.
 */
interface DescribesParameter
{
    /**
     * @throws InvalidApplication when the parameter cannot be filled by this extractor
     */
    public function describe(HandlerParameter $parameter): ParameterDescription;
}
