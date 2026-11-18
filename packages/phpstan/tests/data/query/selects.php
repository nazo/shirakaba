<?php

declare(strict_types=1);

namespace Shirakaba\PHPStan\Tests\Query;

use Shirakaba\Database\Reader;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;

use function PHPStan\Testing\assertType;

function selects(Reader $db, string $dynamic): void
{
    assertType('list<array{id: mixed, title: mixed, total: mixed}>', Select::from('tasks')->columns('id', 'tasks.title')->where('done', '=', false)->column(Sql::raw('COUNT(*)'), 'total')->fetchAll($db));
    assertType('array{id: mixed}|null', Select::from('tasks')->columns('title')->reselect('id')->fetchOne($db));
    assertType('list<array<string, mixed>>', Select::from('tasks')->fetchAll($db));
    assertType('list<array<string, mixed>>', Select::from('tasks')->columns($dynamic)->fetchAll($db));
    assertType('list<array<string, mixed>>', Select::from('tasks')->columns(Sql::raw('1'))->fetchAll($db));
    $query = Select::from('tasks')->columns('id');
    assertType('list<array<string, mixed>>', $query->fetchAll($db));
}
