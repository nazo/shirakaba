<?php

declare(strict_types=1);

namespace Shirakaba\Template;

use RuntimeException;

/**
 * A template could not be found, parsed or rendered. The message names the template, line and
 * column, never the data's values.
 */
final class TemplateError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $template = '',
        public readonly int $templateLine = 0,
        public readonly int $templateColumn = 0,
    ) {
        parent::__construct($template === '' ? $message : sprintf('%s:%d:%d: %s', $template, $templateLine, $templateColumn, $message));
    }
}
