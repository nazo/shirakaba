<?php

declare(strict_types=1);

namespace Shirakaba\Json\Internal;

use Shirakaba\Core\Extraction\Constraint;

/**
 * One constructor parameter of a DTO.
 *
 * @internal
 */
final readonly class Field
{
    /**
     * @param list<Constraint> $constraints
     */
    public function __construct(
        public string $name,
        public Node $node,
        public bool $hasDefault,
        public mixed $default,
        public array $constraints,
        /** An Optional field: absence is kept as such instead of using a default. */
        public bool $optional = false,
        /** The constructor parameter, when the JSON name differs (JsonName). */
        public ?string $parameter = null,
        /** The parameter's doc comment, for documentation. */
        public string $description = '',
    ) {}
}
