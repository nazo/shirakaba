<?php

declare(strict_types=1);

namespace Shirakaba\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a failure into a response (design/05-errors-and-observability.md). The default is ProblemDetails; packages can
 * provide HTML pages or content negotiation.
 *
 * The response status should be the failure's status. The failure's headers (such as
 * Allow and WWW-Authenticate) are re-applied by the application after rendering, so a
 * renderer cannot drop them.
 */
interface ErrorRenderer
{
    public function render(Failure $failure, ServerRequestInterface $request): ResponseInterface;
}
