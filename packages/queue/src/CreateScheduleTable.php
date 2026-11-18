<?php

declare(strict_types=1);

namespace Shirakaba\Queue;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

/**
 * The table that records which scheduled task ran for which minute, so a task runs once per
 * slot however many servers call schedule:run (design/10-background-work.md):
 *
 *     '2026_11_12_create_schedule_runs' => new CreateScheduleTable(),
 */
final readonly class CreateScheduleTable implements Reversible
{
    public function __construct(
        private string $table = 'shirakaba_schedule_runs',
    ) {}

    public function up(Schema $schema): void
    {
        $schema->create($this->table, function (Blueprint $table): void {
            $table->string('task', 100);
            $table->instant('slot');
            $table->instant('created_at');
            $table->primary(['task', 'slot']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop($this->table);
    }
}
