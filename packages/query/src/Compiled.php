<?php

declare(strict_types=1);

namespace Shirakaba\Query;

/**
 * SQL text and its positional parameters, as sent to the database.
 */
final readonly class Compiled
{
    /**
     * @param list<scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters
     */
    public function __construct(
        public string $sql,
        public array $parameters,
    ) {}
}
