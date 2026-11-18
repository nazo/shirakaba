<?php

declare(strict_types=1);

namespace Shirakaba\Query;

/**
 * A raw SQL fragment with its own parameters, the only way SQL text enters a query
 *. Values still go through placeholders:
 *
 *     Sql::raw('LOWER(email) = ?', [$email])
 *     Sql::raw('COUNT(*)')
 */
final readonly class Sql
{
    /**
     * @param list<scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters
     */
    private function __construct(
        public string $sql,
        public array $parameters,
    ) {}

    /**
     * @param list<scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|\BackedEnum|null> $parameters one per "?" in $sql
     * @throws \InvalidArgumentException when the number of placeholders and parameters differ
     */
    public static function raw(string $sql, array $parameters = []): self
    {
        $placeholders = substr_count($sql, '?');
        if ($placeholders !== count($parameters)) {
            throw new \InvalidArgumentException(sprintf('Sql::raw() has %d placeholders but %d parameters.', $placeholders, count($parameters)));
        }

        return new self($sql, $parameters);
    }
}
