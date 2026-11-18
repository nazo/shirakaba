<?php

declare(strict_types=1);

namespace Shirakaba\Examples\Tasks\Migrations;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

final readonly class CreateTasks implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->default('');
            $table->string('status', 20)->default('open');
            $table->instant('created_at');
            $table->integer('version');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('tasks');
    }
}
