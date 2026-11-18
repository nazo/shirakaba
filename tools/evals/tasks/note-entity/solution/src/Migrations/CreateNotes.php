<?php

declare(strict_types=1);

namespace App\Migrations;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

final readonly class CreateNotes implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('author_id');
            $table->string('title', 100);
            $table->instant('created_at');
            $table->foreign('author_id')->references('users');
            $table->index(['created_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('notes');
    }
}
