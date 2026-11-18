<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

/**
 * A #[MapOf] field's value on its way to Json, which writes it as an object even when it is
 * empty or its keys look like list indexes.
 *
 * @internal
 */
final readonly class EncodedMap
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(
        public array $items,
    ) {}
}
