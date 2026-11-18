<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests\Fixtures;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Migration;
use Shirakaba\Migrations\Schema;

final readonly class Risky implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->alter('mig_posts', static function (Blueprint $table): void {
            $table->string('slug', 100);
            $table->string('subtitle', 100)->nullable();
            $table->dropColumn('meta');
            $table->renameColumn('title', 'headline');
            $table->index(['slug']);
            $table->unique(['headline']);
        });
        $schema->drop('mig_old');
        $schema->sql('UPDATE mig_posts SET score = 0');
    }
}
