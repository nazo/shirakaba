<?php

declare(strict_types=1);

namespace Shirakaba\Core\Description;

use Shirakaba\Core\Extraction\Constraint;

final readonly class Property
{
    /**
     * @param list<Constraint> $constraints
     */
    public function __construct(
        public string $name,
        public Type $type,
        public bool $required,
        public bool $hasDefault = false,
        public mixed $default = null,
        public array $constraints = [],
        /** Prose for documentation, such as the property's doc comment; "" when there is none. */
        public string $description = '',
    ) {}
}
