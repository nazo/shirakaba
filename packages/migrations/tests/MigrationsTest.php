<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Database\Dialect\MySql;
use Shirakaba\Database\Dialect\PostgreSql;
use Shirakaba\Database\Dialect\Sqlite;
use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Testing\TestDatabases;
use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Column;
use Shirakaba\Migrations\ForeignKey;
use Shirakaba\Migrations\Linter;
use Shirakaba\Migrations\Migrations;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Migrations\Operation;
use Shirakaba\Migrations\Schema;
use Shirakaba\Migrations\Tests\Fixtures\AddSummary;
use Shirakaba\Migrations\Tests\Fixtures\Broken;
use Shirakaba\Migrations\Tests\Fixtures\CreateAuthors;
use Shirakaba\Migrations\Tests\Fixtures\CreatePosts;
use Shirakaba\Query\Insert;
use Shirakaba\Query\Select;

#[CoversClass(Migrator::class)]
#[CoversClass(Schema::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(Column::class)]
#[CoversClass(ForeignKey::class)]
#[CoversClass(Migrations::class)]
#[CoversClass(Linter::class)]
#[CoversClass(Operation::class)]
final class MigrationsTest extends TestCase
{
    /**
     * @return array<string, array{Database}>
     */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    private static function clean(Database $database): Connection
    {
        $connection = $database->connect();
        foreach (['mig_half', 'mig_posts', 'mig_authors', 'mig_ids', 'shirakaba_migrations'] as $table) {
            $connection->writer()->execute('DROP TABLE IF EXISTS ' . $table);
        }

        return $connection;
    }

    #[DataProvider('databases')]
    public function testMigratesAndRollsBack(Database $database): void
    {
        $connection = self::clean($database);
        $migrator = new Migrator($connection, ['001_authors' => new CreateAuthors(), '002_posts' => new CreatePosts(), '003_summary' => new AddSummary()]);

        self::assertSame(['001_authors' => false, '002_posts' => false, '003_summary' => false], $migrator->status());
        self::assertSame(['001_authors', '002_posts', '003_summary'], array_keys($migrator->migrate()));
        self::assertSame([], $migrator->migrate(), 'A second run has nothing to do.');
        self::assertSame(['001_authors' => true, '002_posts' => true, '003_summary' => true], $migrator->status());

        $db = $connection->writer();
        $author = Insert::into('mig_authors')
            ->values(['email' => 'a@example.com', 'created_at' => new \DateTimeImmutable('2026-11-11T00:00:00Z')])
            ->executeAndGetId($db);
        Insert::into('mig_posts')->values(['author_id' => $author, 'headline' => 'Hi', 'summary' => 'x'])->execute($db);
        self::assertSame("O'Neil", Select::from('mig_authors')->columns('name')->fetchValue($db));
        self::assertTrue((bool) Select::from('mig_authors')->columns('active')->fetchValue($db));

        try {
            Insert::into('mig_posts')->values(['author_id' => 999, 'headline' => 'Orphan'])->execute($db);
            self::fail('The foreign key was not created.');
        } catch (DatabaseError) {
        }

        try {
            $migrator->rollback();
            self::fail('A migration that is not Reversible was rolled back.');
        } catch (LogicException $e) {
            self::assertStringContainsString('003_summary cannot be rolled back', $e->getMessage());
        }
        $partial = new Migrator($connection, ['001_authors' => new CreateAuthors(), '002_posts' => new CreatePosts()]);
        try {
            $partial->migrate();
            self::fail('Unknown applied migrations were ignored.');
        } catch (LogicException $e) {
            self::assertStringContainsString('003_summary', $e->getMessage());
        }
        $db->execute("DELETE FROM shirakaba_migrations WHERE id = '003_summary'");
        self::assertSame(['002_posts', '001_authors'], array_keys($partial->rollback(2)));
        self::assertSame(['001_authors' => false, '002_posts' => false], $partial->status());
    }

    #[DataProvider('databases')]
    public function testDryRunChangesNothing(Database $database): void
    {
        $connection = self::clean($database);
        $planned = new Migrator($connection, ['001_authors' => new CreateAuthors()])->migrate(dryRun: true);

        self::assertStringStartsWith('CREATE TABLE', $planned['001_authors'][0] ?? '');
        self::assertSame(['001_authors' => false], new Migrator($connection, ['001_authors' => new CreateAuthors()])->status());
    }

    #[DataProvider('databases')]
    public function testFailedMigrationsLeaveNothingWhereDdlIsTransactional(Database $database): void
    {
        $connection = self::clean($database);
        try {
            new Migrator($connection, ['001_broken' => new Broken()])->migrate();
            self::fail('The broken migration succeeded.');
        } catch (DatabaseError) {
        }

        $exists = Select::from('shirakaba_migrations')->count($connection->reader());
        self::assertSame(0, $exists);
        if ($connection->dialect->name() !== 'mysql') {
            $this->expectException(DatabaseError::class);
            $connection->reader()->fetchAll('SELECT * FROM mig_half');
        }
    }

    public function testDdlPerDialect(): void
    {
        $sql = static function (\Shirakaba\Database\Dialect\Dialect $dialect): array {
            $schema = new Schema($dialect);
            new CreatePosts()->up($schema);

            return $schema->statements();
        };

        self::assertSame(
            [
                "CREATE TABLE `mig_posts` (\n    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,\n    `author_id` BIGINT NOT NULL,\n    `title` VARCHAR(200) NOT NULL,\n    `meta` JSON NULL,\n    `score` DECIMAL(5, 2) NOT NULL DEFAULT 0,\n    CONSTRAINT `mig_posts_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `mig_authors` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
                'CREATE INDEX `mig_posts_author_id_title_index` ON `mig_posts` (`author_id`, `title`)',
            ],
            $sql(new MySql()),
        );
        self::assertStringContainsString('"id" BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY', $sql(new PostgreSql())[0] ?? '');
        self::assertStringContainsString('"meta" JSONB NULL', $sql(new PostgreSql())[0] ?? '');
        self::assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sql(new Sqlite())[0] ?? '');
    }

    public function testStringDefaultsAreQuotedPerDialect(): void
    {
        $sql = static function (\Shirakaba\Database\Dialect\Dialect $dialect): string {
            $schema = new Schema($dialect);
            $schema->create('t', static function (Blueprint $table): void {
                $table->string('path')->default("C:\\' OR 1=1 --");
            });

            return $schema->statements()[0] ?? '';
        };

        self::assertStringContainsString("DEFAULT 'C:\\\\'' OR 1=1 --'", $sql(new MySql()));
        self::assertStringContainsString("DEFAULT 'C:\\'' OR 1=1 --'", $sql(new PostgreSql()));
    }

    public function testCollations(): void
    {
        $sql = static function (\Shirakaba\Database\Dialect\Dialect $dialect): string {
            $schema = new Schema($dialect);
            $schema->create('t', static function (Blueprint $table): void {
                $table->string('id', 16)->bytewise();
                $table->string('name')->collation(['pgsql' => 'und-x-icu']);
                $table->primary(['id']);
            });

            return $schema->statements()[0] ?? '';
        };

        self::assertStringContainsString('`id` VARCHAR(16) COLLATE `utf8mb4_bin` NOT NULL', $sql(new MySql()));
        self::assertStringContainsString('`name` VARCHAR(255) NOT NULL', $sql(new MySql()));
        self::assertStringContainsString('"id" VARCHAR(16) COLLATE "C" NOT NULL', $sql(new PostgreSql()));
        self::assertStringContainsString('"name" VARCHAR(255) COLLATE "und-x-icu" NOT NULL', $sql(new PostgreSql()));
        self::assertStringContainsString('"id" VARCHAR(16) COLLATE "BINARY" NOT NULL', $sql(new Sqlite()));

        $this->expectException(\InvalidArgumentException::class);
        new Blueprint('t')
            ->string('x')
            ->collation(['pgsql' => 'C" NOT NULL, evil TEXT']);
    }

    #[DataProvider('databases')]
    public function testBytewiseColumnsCompareAndSortByBytes(Database $database): void
    {
        $connection = self::clean($database);
        $schema = new Schema($connection->dialect);
        $schema->create('mig_ids', static function (Blueprint $table): void {
            $table->string('id', 16)->bytewise();
            $table->primary(['id']);
        });
        foreach ($schema->statements() as $statement) {
            $connection->writer()->execute($statement);
        }
        foreach (['b', 'B', 'a-1', 'a_1', 'A'] as $id) {
            $connection->writer()->execute('INSERT INTO mig_ids (id) VALUES (?)', [$id]);
        }

        self::assertSame(['A', 'B', 'a-1', 'a_1', 'b'], array_column($connection->reader()->fetchAll('SELECT id FROM mig_ids ORDER BY id'), 'id'));
        self::assertSame(['b'], array_column($connection->reader()->fetchAll("SELECT id FROM mig_ids WHERE id > 'a_1'"), 'id'));
    }

    public function testSqliteCannotAddForeignKeysLater(): void
    {
        $schema = new Schema(new Sqlite());
        $this->expectException(LogicException::class);
        $schema->alter('t', static function (Blueprint $table): void {
            $table->foreign('x')->references('y');
        });
    }

    public function testLintReportsRiskyChangesButNotNewTables(): void
    {
        $warnings = new Linter(new PostgreSql())->lint([
            '001_authors' => new CreateAuthors(),
            '002_posts' => new CreatePosts(),
            '003_risky' => new \Shirakaba\Migrations\Tests\Fixtures\Risky(),
        ]);

        self::assertSame(['003_risky'], array_keys($warnings));
        self::assertSame(
            [
                'drops column "mig_posts.meta" and its data',
                'renames column "mig_posts" title -> headline; code still running with the old name fails until it is deployed',
                'adds NOT NULL column "mig_posts.slug" without a default, which fails on a table with rows',
                'adds index "mig_posts_slug_index" to existing table "mig_posts", which locks writes while it builds; consider CREATE INDEX CONCURRENTLY in a migration of its own',
                'adds unique index "mig_posts_headline_unique" to existing table "mig_posts", which fails if rows already conflict',
                'drops table "mig_old" and its data',
                'runs raw SQL, which the linter cannot check: UPDATE mig_posts SET score = 0',
            ],
            $warnings['003_risky'] ?? null,
        );
    }

    public function testMigrationIdsAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Migrations(new Database(static fn (): PDO => new PDO('sqlite::memory:')), ['bad id; drop' => new CreateAuthors()]);
    }
}
