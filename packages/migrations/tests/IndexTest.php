<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Database;
use Shirakaba\Database\Dialect\Dialect;
use Shirakaba\Database\Dialect\MySql;
use Shirakaba\Database\Dialect\PostgreSql;
use Shirakaba\Database\Dialect\Sqlite;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Index;
use Shirakaba\Migrations\Linter;
use Shirakaba\Migrations\Migration;
use Shirakaba\Migrations\Schema;

#[CoversClass(Index::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(Schema::class)]
final class IndexTest extends TestCase
{
    private const string SEARCH = "lower(coalesce(cw, '') || ' ' || coalesce(body, ''))";

    /**
     * @param \Closure(Schema): void $define
     * @return list<string>
     */
    private static function statements(Dialect $dialect, \Closure $define): array
    {
        $schema = new Schema($dialect);
        $define($schema);

        return $schema->statements();
    }

    public function testTrigramSearchIndexOnPostgreSql(): void
    {
        self::assertSame(
            [
                'CREATE EXTENSION IF NOT EXISTS "pg_trgm"',
                'CREATE INDEX "notes_search" ON "notes" USING gin (' . self::SEARCH . ' gin_trgm_ops)',
                'ALTER INDEX "notes_search" ALTER COLUMN 1 SET STATISTICS 1000',
                'CREATE UNIQUE INDEX "notes_tag_unique" ON "notes" USING btree ("tag" text_pattern_ops)',
            ],
            self::statements(new PostgreSql(), static function (Schema $schema): void {
                $schema->extension('pg_trgm');
                $schema->alter('notes', static function (Blueprint $table): void {
                    $table->expressionIndex('notes_search', self::SEARCH)->using('gin')->operatorClass('gin_trgm_ops')->statistics(1000);
                    $table->unique(['tag'], 'notes_tag_unique')->using('btree')->operatorClass('text_pattern_ops');
                });
            }),
        );
    }

    public function testExpressionIndexesOnMySqlAndSqlite(): void
    {
        $define = static function (Schema $schema): void {
            $schema->alter('users', static function (Blueprint $table): void {
                $table->expressionIndex('users_email_lower', 'lower(email)', unique: true);
                $table->index(['name'])->using('btree');
            });
        };

        self::assertSame(
            [
                'CREATE UNIQUE INDEX `users_email_lower` ON `users` ((lower(email)))',
                'CREATE INDEX `users_name_index` ON `users` (`name`) USING BTREE',
            ],
            self::statements(new MySql(), $define),
        );
        self::assertSame(
            [
                'CREATE UNIQUE INDEX "users_email_lower" ON "users" (lower(email))',
                'CREATE INDEX "users_name_index" ON "users" ("name")',
            ],
            self::statements(new Sqlite(), $define),
        );
    }

    /**
     * @return iterable<string, array{Dialect, \Closure(Schema): void}>
     */
    public static function unsupported(): iterable
    {
        $alter = static fn (\Closure $index): \Closure => static fn (Schema $schema) => $schema->alter('notes', $index);
        yield 'gin on MySQL' => [new MySql(), $alter(static fn (Blueprint $t) => $t->index(['body'])->using('gin'))];
        yield 'hash on SQLite' => [new Sqlite(), $alter(static fn (Blueprint $t) => $t->index(['body'])->using('hash'))];
        yield 'operator class on MySQL' => [new MySql(), $alter(static fn (Blueprint $t) => $t->index(['body'])->operatorClass('x_ops'))];
        yield 'statistics on a column index' => [new PostgreSql(), $alter(static fn (Blueprint $t) => $t->index(['body'])->statistics(500))];
        yield 'statistics on SQLite' => [new Sqlite(), $alter(static fn (Blueprint $t) => $t->expressionIndex('n', 'lower(body)')->statistics(500))];
        yield 'extension on MySQL' => [new MySql(), static fn (Schema $schema) => $schema->extension('pg_trgm')];
    }

    /**
     * @param \Closure(Schema): void $define
     */
    #[DataProvider('unsupported')]
    public function testRefusesWhatTheDatabaseCannotExpress(Dialect $dialect, \Closure $define): void
    {
        $this->expectException(LogicException::class);
        self::statements($dialect, $define);
    }

    public function testLintTreatsNewIndexesAsBefore(): void
    {
        $migration = new class implements Migration {
            public function up(Schema $schema): void
            {
                $schema->extension('pg_trgm');
                $schema->alter('notes', static function (Blueprint $table): void {
                    $table->expressionIndex('notes_search', 'lower(body)')->using('gin');
                });
            }
        };

        $warnings = new Linter(new PostgreSql())->lint(['search' => $migration]);
        $found = $warnings['search'] ?? [];
        self::assertCount(1, $found);
        self::assertStringContainsString('adds index "notes_search" to existing table "notes"', implode("\n", $found));
    }

    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    #[DataProvider('databases')]
    public function testExpressionIndexesRunOnEveryDatabase(Database $database): void
    {
        $db = $database->connect()->writer();
        $db->execute('DROP TABLE IF EXISTS mig_search');
        $dialect = $db->dialect();
        // The expression is SQL as written; MySQL concatenates with CONCAT, not ||.
        $text = $dialect->name() === 'mysql'
            ? "lower(concat(coalesce(cw, ''), ' ', coalesce(body, '')))"
            : "lower(coalesce(cw, '') || ' ' || coalesce(body, ''))";
        $schema = new Schema($dialect);
        if ($dialect->name() === 'pgsql') {
            $schema->extension('pg_trgm');
        }
        $schema->create('mig_search', static function (Blueprint $table) use ($dialect, $text): void {
            $table->id();
            $table->string('cw', 100)->nullable();
            $table->string('body', 100)->nullable();
            $index = $table->expressionIndex('mig_search_text', $text);
            if ($dialect->name() === 'pgsql') {
                $index->using('gin')->operatorClass('gin_trgm_ops')->statistics(1000);
            }
        });
        foreach ($schema->statements() as $statement) {
            $db->execute($statement);
        }
        $db->execute("INSERT INTO mig_search (cw, body) VALUES (NULL, 'Hello World'), ('cw', 'other')");

        self::assertEquals(1, $db->fetchValue('SELECT COUNT(*) FROM mig_search WHERE ' . $text . " LIKE '%world%'"));
        $db->execute('DROP TABLE mig_search');
    }
}
