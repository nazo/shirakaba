<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\DatabaseBridge\MigrationCommands;

#[CoversClass(MigrationCommands::class)]
final class MigrationCommandsTest extends TestCase
{
    public function testCommandLine(): void
    {
        $directory = sys_get_temp_dir() . '/shirakaba-migrate-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $config = $directory . '/migrations.php';
        file_put_contents($config, sprintf(<<<'PHP'
            <?php
            use Shirakaba\Migrations\Blueprint;
            use Shirakaba\Migrations\Reversible;
            use Shirakaba\Migrations\Schema;

            return new Shirakaba\Migrations\Migrations(
                new Shirakaba\Database\Database(static fn (): PDO => new PDO('sqlite:%s/db.sqlite')),
                ['001_authors' => new class implements Reversible {
                    public function up(Schema $schema): void
                    {
                        $schema->create('mig_authors', static function (Blueprint $table): void {
                            $table->id();
                            $table->string('email', 100)->unique();
                        });
                    }

                    public function down(Schema $schema): void
                    {
                        $schema->drop('mig_authors');
                    }
                }],
            );
            PHP, $directory));
        $bin = dirname(__DIR__) . '/bin/shirakaba-migrate';
        try {
            $run = static function (string $arguments) use ($bin, $config): array {
                exec(
                    sprintf('%s %s %s --config=%s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($bin), $arguments, escapeshellarg($config)),
                    $output,
                    $status,
                );

                return [$status, implode("\n", $output)];
            };

            self::assertSame([0, '[ ] 001_authors'], $run('status'));
            self::assertStringContainsString('CREATE TABLE "mig_authors"', $run('migrate --dry-run')[1]);
            self::assertSame([0, 'Applied 001_authors'], $run('migrate'));
            self::assertSame([0, 'Nothing to migrate.'], $run('migrate'));
            self::assertSame([0, 'No warnings.'], $run('lint --all'));
            self::assertSame([0, 'Rolled back 001_authors'], $run('rollback'));
            self::assertSame(2, $run('explode')[0]);
        } finally {
            $files = glob($directory . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir($directory);
        }
    }
}
