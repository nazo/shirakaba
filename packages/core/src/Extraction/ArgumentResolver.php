<?php

declare(strict_types=1);

namespace Shirakaba\Core\Extraction;

use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\InvalidApplication;

/**
 * Produces one handler argument for a request. Created by ParameterExtractor::plan().
 */
interface ArgumentResolver
{
    /**
     * @throws HttpFailure usually an ExtractionFailure, when the request does not provide a valid value
     * @throws InvalidApplication when the application is set up wrongly, such as a service the
     *                            resolver needs that was never provided
     */
    public function resolve(Exchange $exchange): mixed;
}
