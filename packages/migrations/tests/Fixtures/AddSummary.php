<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests\Fixtures;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Migration;
use Shirakaba\Migrations\Schema;

/**
 * Not reversible on purpose.
 */
final readonly class AddSummary implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->alter('mig_posts', static function (Blueprint $table): void {
            $table->text('summary')->nullable();
            $table->renameColumn('title', 'headline');
        });
    }
}
