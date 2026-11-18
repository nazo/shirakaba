<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

/**
 * Converts a property to and from its column, for types the ORM does not know:
 * `#[Column(type: MoneyType::class)] public Money $price`. Implementations need a
 * constructor without arguments.
 */
interface ColumnType
{
    /**
     * @return scalar|\DateTimeImmutable|\Shirakaba\Time\LocalDate|null
     */
    public function toDatabase(mixed $value): mixed;

    /**
     * @param mixed $value as the driver returned it
     */
    public function fromDatabase(mixed $value): mixed;
}
