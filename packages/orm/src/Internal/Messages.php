<?php

declare(strict_types=1);

namespace Shirakaba\Orm\Internal;

/**
 * Points an error message at its page in shirakaba/docs, like the framework's Messages, kept
 * here so the ORM does not depend on the framework (design/08-database.md).
 *
 * @internal
 */
final readonly class Messages
{
    /**
     * @param non-empty-string $code lowercase words joined by hyphens
     */
    public static function see(string $code): string
    {
        return sprintf(' See vendor/shirakaba/docs/messages/%s.md.', $code);
    }
}
