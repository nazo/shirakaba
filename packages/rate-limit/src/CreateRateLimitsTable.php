<?php

declare(strict_types=1);

namespace Shirakaba\RateLimit;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

/**
 * The table of DatabaseRateLimitStore; add it to the application's migrations:
 *
 *     '2026_11_14_create_rate_limits' => new CreateRateLimitsTable(),
 */
final readonly class CreateRateLimitsTable implements Reversible
{
    public function __construct(
        private string $table = 'shirakaba_rate_limits',
    ) {}

    public function up(Schema $schema): void
    {
        $schema->create($this->table, function (Blueprint $table): void {
            $table->string('bucket', 191);
            $table->instant('window_start');
            $table->integer('hits');
            $table->instant('expires_at');
            $table->primary(['bucket', 'window_start']);
            $table->index(['expires_at'], $this->table . '_expires_index');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop($this->table);
    }
}
