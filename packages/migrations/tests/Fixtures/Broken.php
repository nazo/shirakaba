<?php

declare(strict_types=1);

namespace Shirakaba\Migrations\Tests\Fixtures;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Migration;
use Shirakaba\Migrations\Schema;

final readonly class Broken implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('mig_half', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->sql('THIS IS NOT SQL');
    }
}
