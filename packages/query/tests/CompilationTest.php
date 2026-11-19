<?php

declare(strict_types=1);

namespace Shirakaba\Query\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Dialect\MySql;
use Shirakaba\Database\Dialect\PostgreSql;
use Shirakaba\Database\Dialect\Sqlite;
use Shirakaba\Query\Conditions;
use Shirakaba\Query\Delete;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Internal\Output;
use Shirakaba\Query\Select;
use Shirakaba\Query\Sql;
use Shirakaba\Query\Update;

#[CoversClass(Select::class)]
#[CoversClass(Insert::class)]
#[CoversClass(Update::class)]
#[CoversClass(Delete::class)]
#[CoversClass(Conditions::class)]
#[CoversClass(Output::class)]
#[CoversClass(Sql::class)]
final class CompilationTest extends TestCase
{
    public function testSelectWithEverything(): void
    {
        $query = Select::from('tasks', 't')
            ->distinct()
            ->columns('t.id', 't.title')
            ->column(Sql::raw('COUNT(c.id)'), 'comments')
            ->leftJoin('comments', 'c.task_id', '=', 't.id', 'c')
            ->where('t.status', '=', 'open')
            ->whereGroup(static fn (Conditions $c): Conditions => $c->where('t.priority', '>', 3)->orWhereNull('t.due_on'))
            ->whereIn('t.owner_id', [1, 2])
            ->groupBy('t.id', 't.title')
            ->having(static fn (Conditions $c): Conditions => $c->where(Sql::raw('COUNT(c.id)'), '>=', 2))
            ->orderBy('t.id', 'desc')
            ->limit(10)
            ->offset(20);

        $mysql = $query->toSql(new MySql());
        self::assertSame(
            'SELECT DISTINCT `t`.`id`, `t`.`title`, COUNT(c.id) AS `comments` FROM `tasks` AS `t` LEFT JOIN `comments` AS `c` ON `c`.`task_id` = `t`.`id`'
                . ' WHERE `t`.`status` = ? AND (`t`.`priority` > ? OR `t`.`due_on` IS NULL) AND `t`.`owner_id` IN (?, ?)'
                . ' GROUP BY `t`.`id`, `t`.`title` HAVING COUNT(c.id) >= ? ORDER BY `t`.`id` DESC LIMIT 10 OFFSET 20',
            $mysql->sql,
        );
        self::assertSame(['open', 3, 1, 2, 2], $mysql->parameters);
        self::assertStringContainsString('FROM "tasks" AS "t" LEFT JOIN "comments" AS "c"', $query->toSql(new PostgreSql())->sql);
    }

    public function testDialectDifferences(): void
    {
        $offsetOnly = Select::from('tasks')->offset(5)->forUpdate();

        self::assertSame('SELECT * FROM `tasks` LIMIT 18446744073709551615 OFFSET 5 FOR UPDATE', $offsetOnly->toSql(new MySql())->sql);
        self::assertSame('SELECT * FROM "tasks" LIMIT ALL OFFSET 5 FOR UPDATE', $offsetOnly->toSql(new PostgreSql())->sql);
        self::assertSame('SELECT * FROM "tasks" LIMIT -1 OFFSET 5', $offsetOnly->toSql(new Sqlite())->sql, 'SQLite has no row locks.');

        $upsert = Insert::into('users')->values(['email' => 'a@example.com', 'name' => 'A'])->onConflict(['email'], ['name']);
        self::assertSame(
            'INSERT INTO `users` (`email`, `name`) VALUES (?, ?) AS excluded ON DUPLICATE KEY UPDATE `name` = excluded.`name`',
            $upsert->toSql(new MySql())->sql,
        );
        self::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (?, ?) ON CONFLICT ("email") DO UPDATE SET "name" = excluded."name"',
            $upsert->toSql(new PostgreSql())->sql,
        );

        $counter = Insert::into('counts')
            ->values(['key' => 'a', 'n' => 1])
            ->onConflict(['key'], ['n' => Sql::raw('counts.n + ?', [5])]);
        $compiled = $counter->toSql(new PostgreSql());
        self::assertSame('INSERT INTO "counts" ("key", "n") VALUES (?, ?) ON CONFLICT ("key") DO UPDATE SET "n" = counts.n + ?', $compiled->sql);
        self::assertSame(['a', 1, 5], $compiled->parameters);
        self::assertSame(
            'INSERT INTO `counts` (`key`, `n`) VALUES (?, ?) AS excluded ON DUPLICATE KEY UPDATE `n` = counts.n + ?',
            $counter->toSql(new MySql())->sql,
        );
    }

    public function testEmptyInListsAreValidSql(): void
    {
        self::assertSame('SELECT * FROM "t" WHERE 1 = 0', Select::from('t')->whereIn('id', [])->toSql(new Sqlite())->sql);
        self::assertSame('SELECT * FROM "t" WHERE 1 = 1', Select::from('t')->whereNotIn('id', [])->toSql(new Sqlite())->sql);
    }

    public function testSubqueriesCtesUnionsAndKeysets(): void
    {
        $recent = Select::from('tasks')->columns('id')->where('created_at', '>', '2026-01-01');
        $query = Select::from('recent')
            ->with('recent', $recent)
            ->whereExists(Select::from('comments')->whereColumn('comments.task_id', '=', 'recent.id'))
            ->union(Select::from('archived')->columns('id'), all: true)
            ->orderBy('id')
            ->after(['id' => 10]);
        $compiled = $query->toSql(new Sqlite());

        self::assertSame(
            'WITH "recent" AS (SELECT "id" FROM "tasks" WHERE "created_at" > ?) SELECT * FROM "recent"'
                . ' WHERE EXISTS (SELECT * FROM "comments" WHERE "comments"."task_id" = "recent"."id") AND "id" > ?'
                . ' UNION ALL SELECT "id" FROM "archived" ORDER BY "id" ASC',
            $compiled->sql,
        );
        self::assertSame(['2026-01-01', 10], $compiled->parameters);
        self::assertStringContainsString(
            '("created_at", "id") < (?, ?)',
            Select::from('t')
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->after(['created_at' => 'x', 'id' => 1])
                ->toSql(new Sqlite())
                ->sql,
        );
    }

    public function testRejectsUnsafeNamesAndOperators(): void
    {
        $accepted = [];
        foreach ([
            static fn () => Select::from('tasks; DROP TABLE users')->toSql(new Sqlite()),
            static fn () => Select::from('tasks')->orderBy('id desc')->toSql(new Sqlite()),
            static fn () => Select::from('tasks')->where('id', 'OR 1=1 --', 1),
            static fn () => Select::from('tasks')->where('id', '=', null),
            static fn () => Sql::raw('a = ? AND b = ?', [1]),
        ] as $index => $unsafe) {
            try {
                $unsafe();
            } catch (InvalidArgumentException) {
                continue;
            }
            $accepted[] = $index;
        }
        self::assertSame([], $accepted, 'These cases must be refused.');
    }

    public function testUpdatesAndDeletesNeedAWhereClause(): void
    {
        self::assertSame(
            'UPDATE "tasks" SET "done" = ?, "hits" = hits + 1 WHERE "id" = ?',
            Update::table('tasks')
                ->set(['done' => true, 'hits' => Sql::raw('hits + 1')])
                ->where('id', '=', 5)
                ->toSql(new Sqlite())
                ->sql,
        );
        self::assertSame('DELETE FROM "tasks"', Delete::from('tasks')->everyRow()->toSql(new Sqlite())->sql);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no WHERE clause');
        Delete::from('tasks')->toSql(new Sqlite());
    }

    public function testReturningNeedsDialectSupport(): void
    {
        $insert = Insert::into('tasks')->values(['title' => 'a'])->returning('id');

        self::assertSame('INSERT INTO "tasks" ("title") VALUES (?) RETURNING "id"', $insert->toSql(new PostgreSql())->sql);
        $this->expectException(LogicException::class);
        $insert->toSql(new MySql());
    }
}
