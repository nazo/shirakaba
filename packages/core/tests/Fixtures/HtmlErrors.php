<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests\Fixtures;

use Shirakaba\Core\Http\ErrorRenderer;
use Shirakaba\Core\Http\Failure;
use Shirakaba\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An HTML error renderer that "forgets" headers, to prove the core re-applies them.
 */
final readonly class HtmlErrors implements ErrorRenderer
{
    public function render(Failure $failure, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            $failure->status,
            ['Content-Type' => 'text/html; charset=utf-8'],
            sprintf('<h1>%d</h1><p>%s</p><p>%s</p>', $failure->status, htmlspecialchars($failure->detail), $request->getMethod()),
        );
    }
}
