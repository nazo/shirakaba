<?php

declare(strict_types=1);

namespace Shirakaba\Template;

/**
 * Markup the application vouches for, printed without escaping in HTML templates and refused
 * in text templates (design/12-mail-and-templates.md). Build it only from trusted markup, never from user input.
 */
final readonly class Html
{
    public function __construct(
        public string $markup,
    ) {}
}
