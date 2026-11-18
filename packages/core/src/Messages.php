<?php

declare(strict_types=1);

namespace Shirakaba\Core;

/**
 * Points an error message at its page in shirakaba/docs (design/13-static-analysis-and-agents.md): the cause, the
 * fixes with their trade-offs and a correct example, matching the installed version.
 *
 *     throw new InvalidApplication($message . Messages::see('handler-parameter'));
 *
 * Every code has a page at packages/docs/messages/<code>.md; a test checks both ways.
 */
final readonly class Messages
{
    public const string DIRECTORY = 'vendor/shirakaba/docs/messages';

    /**
     * " See vendor/shirakaba/docs/messages/<code>.md." to append to a message.
     *
     * @param non-empty-string $code lowercase words joined by hyphens
     */
    public static function see(string $code): string
    {
        return sprintf(' See %s/%s.md.', self::DIRECTORY, $code);
    }
}
