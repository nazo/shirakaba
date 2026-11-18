<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests\Fixtures;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

final readonly class CreateAuthors implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('mig_authors', static function (Blueprint $table): void {
            $table->id();
            $table->string('email', 100)->unique();
            $table->string('name', 100)->default("O'Neil");
            $table->boolean('active')->default(true);
            $table->instant('created_at');
            $table->date('born_on')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('mig_authors');
    }
}
