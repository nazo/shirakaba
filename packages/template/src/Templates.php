<?php

declare(strict_types=1);

namespace Shirakaba\Template;

use Shirakaba\Template\Internal\Parsed;
use Shirakaba\Template\Internal\ParsedTemplates;
use Shirakaba\Template\Internal\Renderer;

/**
 * Renders templates from a directory with data from an object (design/12-mail-and-templates.md):
 *
 *     $templates = new Templates(new TemplateDirectory(__DIR__ . '/templates'));   // application-scoped
 *     $text = $templates->render('welcome.txt', new Welcome(name: $user->name, link: $link), 'ja');
 *
 * `.html` templates escape every printed value except Html; `.txt` templates print values as
 * they are and refuse Html. A template that starts with {% layout "mail" %} is rendered into
 * mail.txt or mail.html (same extension, same locale) as {{ content }}.
 */
final readonly class Templates
{
    private ParsedTemplates $parsed;

    public function __construct(
        public TemplateDirectory $directory,
    ) {
        $this->parsed = new ParsedTemplates();
    }

    /**
     * @throws TemplateError when the template is missing or wrong, or the data does not fit it
     */
    public function render(string $name, object $data, ?string $locale = null): string
    {
        $template = $this->load($name, $locale);
        $output = Renderer::render($template, $data);
        $seen = [$template->name => true];
        while ($template->layout !== null) {
            $layoutName = $template->layout . substr($name, (int) strrpos($name, '.'));
            $template = $this->load($layoutName, $locale);
            if (isset($seen[$template->name])) {
                throw new TemplateError(sprintf('The layouts of %s form a cycle.', $name));
            }
            $seen[$template->name] = true;
            $output = Renderer::render($template, $data, ['content' => $template->html ? new Html($output) : $output]);
        }

        return $output;
    }

    /**
     * Whether $name exists in $locale or without one, such as an optional HTML body.
     *
     * @throws TemplateError when the name or locale is not allowed
     */
    public function exists(string $name, ?string $locale = null): bool
    {
        return $this->directory->find($name, $locale) !== null;
    }

    /**
     * @throws TemplateError
     */
    private function load(string $name, ?string $locale): Parsed
    {
        $path = $this->directory->find($name, $locale) ?? throw new TemplateError(sprintf('There is no template "%s"%s.', $name, $locale === null ? '' : sprintf(' for locale "%s"', $locale)));

        return $this->parsed->get($path, $this->directory->nameOf($path));
    }
}
