<?php

declare(strict_types=1);

namespace App\Migrations;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

final readonly class CreateUsers implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 200)->unique();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('users');
    }
}
