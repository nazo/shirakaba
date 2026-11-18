<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

/**
 * The table of the database queue; add it to the application's migrations:
 *
 *     '2026_11_12_create_jobs' => new CreateJobsTable(),
 */
final readonly class CreateJobsTable implements Reversible
{
    public function __construct(
        private string $table = 'shirakaba_jobs',
    ) {}

    public function up(Schema $schema): void
    {
        $schema->create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 100);
            $table->string('type', 255);
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts');
            $table->instant('available_at');
            $table->instant('reserved_until')->nullable();
            $table->string('reservation', 32)->nullable();
            $table->string('unique_key', 191)->nullable()->unique();
            $table->string('traceparent', 55)->nullable();
            $table->instant('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->instant('created_at');
            $table->index(['queue', 'available_at'], $this->table . '_due_index');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop($this->table);
    }
}
