<?php

declare(strict_types=1);

namespace Shirakaba\Form;

use Attribute;

/**
 * Declares the item type of a repeated form field (`tags[]=a&tags[]=b`):
 * `#[FormList('string')] array $tags = []`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FormList
{
    /**
     * @param string $type "string", "int", "float", "bool" or a backed enum
     */
    public function __construct(
        public string $type,
    ) {}
}
