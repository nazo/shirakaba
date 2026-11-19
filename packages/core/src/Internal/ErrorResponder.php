<?php

declare(strict_types=1);

namespace Shirakaba\Core\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Shirakaba\Core\Http\ErrorRenderer;
use Shirakaba\Core\Http\Failure;
use Shirakaba\Core\Http\HttpFailure;
use Shirakaba\Core\Http\TranslatesFailures;
use Throwable;

/**
 * Applies the configured renderer and enforces what design/05-errors-and-observability.md guarantees regardless of it:
 * unexpected throwables become a bare 500 unless a translator turns them into a failure
 * (design/08-database.md), and the failure's headers are always present.
 *
 * @internal
 */
final readonly class ErrorResponder
{
    /**
     * @param list<TranslatesFailures> $translators
     */
    public function __construct(
        private ErrorRenderer $renderer,
        private array $translators = [],
    ) {}

    public function throwable(Throwable $error, ServerRequestInterface $request): ResponseInterface
    {
        if (!$error instanceof HttpFailure) {
            foreach ($this->translators as $translator) {
                $translated = $translator->translate($error);
                if ($translated !== null) {
                    $error = $translated;
                    break;
                }
            }
        }

        return $this->failure(Failure::fromThrowable($error), $request);
    }

    public function failure(Failure $failure, ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->renderer->render($failure, $request);
        foreach ($failure->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
