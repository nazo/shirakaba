<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

use ReflectionParameter;

/**
 * Reads the prose of doc comments for descriptions, such as the description of a DTO field
 * in OpenAPI.
 */
final readonly class DocComment
{
    /**
     * The doc comment of a constructor parameter: the comment on the promoted property, or
     * on the class property of the same name when the parameter is not promoted.
     */
    public static function ofParameter(ReflectionParameter $parameter): string
    {
        $class = $parameter->getDeclaringClass();
        if ($class === null || !$class->hasProperty($parameter->getName())) {
            return '';
        }
        $comment = $class->getProperty($parameter->getName())->getDocComment();

        return $comment === false ? '' : self::text($comment);
    }

    /**
     * The text of a doc comment without its delimiters, leading asterisks and tags such as
     * `@var`. Lines of one paragraph are joined with spaces; paragraphs are separated by a
     * blank line.
     */
    public static function text(string $comment): string
    {
        $comment = preg_replace(['#^/\*\*#', '#\*/$#'], '', trim($comment)) ?? '';
        $paragraphs = [];
        $current = [];
        $lines = preg_split('/\R/', $comment);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line) ?? '');
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '') {
                if ($current !== []) {
                    $paragraphs[] = implode(' ', $current);
                    $current = [];
                }
                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $paragraphs[] = implode(' ', $current);
        }

        return implode("\n\n", $paragraphs);
    }
}
