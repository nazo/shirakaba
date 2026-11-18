<?php

declare(strict_types=1);

namespace Shirakaba\Template\Internal;

use Shirakaba\Template\TemplateError;

/**
 * Parsed templates by file, kept for the life of the engine. Templates do not change while an
 * application runs, and a parsed tree holds no request data (design/03-runtimes-and-scopes.md).
 *
 * @internal
 */
final class ParsedTemplates
{
    /** @var array<string, Parsed> */
    private array $parsed = [];

    /**
     * @throws TemplateError
     */
    public function get(string $path, string $name): Parsed
    {
        if (!isset($this->parsed[$path])) {
            $source = @file_get_contents($path);
            if ($source === false) {
                throw new TemplateError(sprintf('Unable to read %s.', $name));
            }
            $this->parsed[$path] = Parser::parse($name, $source, str_ends_with($path, '.html'));
        }

        return $this->parsed[$path];
    }
}
