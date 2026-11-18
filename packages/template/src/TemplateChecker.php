<?php

declare(strict_types=1);

namespace Shirakaba\Template;

use Shirakaba\Template\Internal\CheckContext;
use Shirakaba\Template\Internal\Parser;

/**
 * Finds template mistakes before a mail is sent (design/12-mail-and-templates.md): every template is parsed, and the
 * templates declared with their data class are checked against it: each path must name public
 * properties, printed values must be printable, conditions must test booleans, strings, lists
 * or null, and for blocks must iterate lists (with item types from `list<T>` in PHPDoc).
 *
 *     $problems = new TemplateChecker($directory)->check(['welcome' => Welcome::class]);
 *
 * A declaration covers every file of that template: welcome.txt, welcome.html,
 * welcome.subject.txt and their locales.
 */
final readonly class TemplateChecker
{
    public function __construct(
        private TemplateDirectory $directory,
    ) {}

    /**
     * @param array<string, class-string> $declared template name (without extension) => data class
     * @return list<string> problems, each naming the template, line and column
     */
    public function check(array $declared = []): array
    {
        $problems = [];
        foreach ($this->directory->names() as $name) {
            $path = $this->directory->find($name);
            $source = $path === null ? false : @file_get_contents($path);
            if ($source === false) {
                $problems[] = sprintf('%s: unable to read the file.', $name);
                continue;
            }
            try {
                $parsed = Parser::parse($name, $source, str_ends_with($name, '.html'));
            } catch (TemplateError $e) {
                $problems[] = $e->getMessage();
                continue;
            }
            $class = self::declaredClass($name, $declared);
            if ($class !== null) {
                // A layout prints the rendered template as {{ content }}.
                $problems = [...$problems, ...new CheckContext($parsed, $class)->nodes($parsed->nodes, ['content' => [['scalar', 'content']]])];
            }
        }

        return $problems;
    }

    /**
     * @param array<string, class-string> $declared
     * @return class-string|null
     */
    private static function declaredClass(string $name, array $declared): ?string
    {
        $base = substr($name, 0, (int) strrpos($name, '.'));
        foreach ($declared as $template => $class) {
            if ($base === $template || str_starts_with($base, $template . '.')) {
                return $class;
            }
        }

        return null;
    }
}
