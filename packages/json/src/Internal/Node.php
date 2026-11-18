<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Description\Type;

/**
 * One step of a mapping plan, built from reflection at build time (design/06-json-and-openapi.md).
 *
 * @internal
 */
interface Node
{
    /**
     * Maps a decoded JSON value. On failure it records problems and returns null; callers
     * compare the problem count before and after to know whether it failed.
     */
    public function map(mixed $value, string $pointer, Problems $problems): mixed;

    public function type(): Type;
}
