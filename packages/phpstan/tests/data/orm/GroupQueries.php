<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Orm;

use Shirakaba\Database\Reader;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\PropertyConditions;

function groupQueries(Reader $db): void
{
    Entities::query(Note::class)->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->where('body', '=', 'x')->orWhere('pinned', '=', true))->fetch($db);
    Entities::query(Note::class)->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->where('title', '=', 'x'))->fetch($db);
    Entities::query(Note::class)->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->orWhere('pinned', '=', 'yes'))->fetch($db);
    Entities::query(Note::class)->whereGroup(static fn (PropertyConditions $c): PropertyConditions => $c->whereNull('body')->orWhereGroup(static fn (PropertyConditions $g): PropertyConditions => $g->orWhereIn('created', [])))->fetch($db);
}
