<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Scopes;

use Shirakaba\Database\Reader;
use Shirakaba\Database\Writer;
use Shirakaba\Orm\Entities;
use Shirakaba\Orm\Scope;

/**
 * @param list<Scope> $scopes
 */
function queries(Reader $db, Writer $writer, InOrganization $org, array $scopes, Board $board): void
{
    Entities::query(Board::class)->scoped($org)->where('title', '=', 'x')->fetch($db);
    Entities::query(Board::class)->where('title', '=', 'x')->fetch($db);
    Entities::query(Board::class)->withoutScope(InOrganization::class)->count($db);
    Entities::query(Board::class)->orderBy('id')->first($db);
    Entities::query(Board::class)->scoped(...$scopes)->fetch($db);
    Entities::find(Board::class, 1, $db, [$org]);
    Entities::get(Board::class, 1, $db);
    Entities::get(Board::class, 1, $db, scopes: [$org]);
    Entities::update($board, $writer, scopes: [$org]);
    Entities::delete($board, $writer);
    Entities::insert($board, $writer, scopes: [$org]);
    Entities::insert($board, $writer);
    Entities::load([$board], 'cards', $db, [$org]);
    Entities::load([$board], 'cards', $db);
    Entities::find(Board::class, 1, $db, $scopes);
    $query = Entities::query(Board::class);
    $query->fetch($db);
}
