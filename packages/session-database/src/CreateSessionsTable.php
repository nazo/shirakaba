<?php

declare(strict_types=1);

namespace Shirakaba\SessionDatabase;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

/**
 * The table of DatabaseSessionStore; add it to the application's migrations:
 *
 *     '2026_11_18_create_sessions' => new CreateSessionsTable(),
 */
final readonly class CreateSessionsTable implements Reversible
{
    public function __construct(
        private string $table = 'shirakaba_sessions',
    ) {}

    public function up(Schema $schema): void
    {
        $schema->create($this->table, function (Blueprint $table): void {
            $table->string('id_hash', 64);
            $table->text('data');
            $table->instant('expires_at');
            $table->primary(['id_hash']);
            $table->index(['expires_at'], $this->table . '_expires_index');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop($this->table);
    }
}
