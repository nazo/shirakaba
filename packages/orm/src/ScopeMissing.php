<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use LogicException;

/**
 * A query of an entity with a #[RequiredScope] neither passed the scope nor switched it off;
 * a programming error, raised before anything is read.
 */
final class ScopeMissing extends LogicException
{
    public static function of(string $entity, string $scope): self
    {
        return new self(
            sprintf('Queries of %s need ->scoped(new %s(...)), or ->withoutScope(%s::class) to read across it.', $entity, $scope, $scope)
                . \Shirakaba\Orm\Internal\Messages::see('scope-missing'),
        );
    }

    public static function onInsert(string $entity, string $scope): self
    {
        return new self(
            sprintf('Inserting %s needs its scope: Entities::insert($entity, $db, scopes: [new %s(...)]).', $entity, $scope)
                . \Shirakaba\Orm\Internal\Messages::see('scope-missing'),
        );
    }
}
