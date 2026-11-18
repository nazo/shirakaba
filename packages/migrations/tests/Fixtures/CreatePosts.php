<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests\Fixtures;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

final readonly class CreatePosts implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('mig_posts', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('author_id');
            $table->string('title', 200);
            $table->json('meta')->nullable();
            $table->decimal('score', 5, 2)->default(0);
            $table->foreign('author_id')->references('mig_authors')->onDelete('cascade');
            $table->index(['author_id', 'title']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('mig_posts');
    }
}
