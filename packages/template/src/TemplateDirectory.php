<?php

declare(strict_types=1);

namespace Shirakaba\Template;

use InvalidArgumentException;

/**
 * Where templates live (design/12-mail-and-templates.md). Templates are found by name, such as "welcome.txt" or
 * "mail/welcome.html", never by a path from outside: names with "..", a leading slash or other
 * characters are refused. With a locale, "welcome.txt" is looked up as "welcome.ja-JP.txt",
 * then "welcome.ja.txt", then "welcome.txt".
 */
final readonly class TemplateDirectory
{
    private string $root;

    /**
     * @throws InvalidArgumentException when the directory does not exist
     */
    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException(sprintf('The template directory "%s" does not exist.', $root));
        }
        $this->root = $real;
    }

    /**
     * The file for $name in $locale, or null when there is none.
     *
     * @throws TemplateError when the name or locale is not allowed
     */
    public function find(string $name, ?string $locale = null): ?string
    {
        if (preg_match('#\A[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*(\.[A-Za-z0-9_-]+)*\.(txt|html)\z#', $name) !== 1) {
            throw new TemplateError(sprintf('"%s" is not a template name; use letters, digits, "_", "-", "/" between directories and a .txt or .html extension.', $name));
        }
        if ($locale !== null && preg_match('/\A[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*\z/', $locale) !== 1) {
            throw new TemplateError(sprintf('"%s" is not a locale.', $locale));
        }
        $dot = (int) strrpos($name, '.');
        $base = substr($name, 0, $dot);
        $extension = substr($name, $dot);
        $candidates = [];
        if ($locale !== null) {
            $parts = preg_split('/[_-]/', $locale);
            $parts = $parts === false ? [$locale] : $parts;
            for ($i = count($parts); $i > 0; $i--) {
                $candidates[] = $base . '.' . implode('-', array_slice($parts, 0, $i)) . $extension;
            }
            if (str_contains($locale, '_')) {
                array_unshift($candidates, $base . '.' . $locale . $extension);
            }
        }
        $candidates[] = $name;
        foreach ($candidates as $candidate) {
            $path = $this->root . '/' . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Every template file, by name relative to the directory.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['txt', 'html'], true)) {
                $names[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($names);

        return $names;
    }

    /**
     * The name of a file returned by find().
     */
    public function nameOf(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }
}
