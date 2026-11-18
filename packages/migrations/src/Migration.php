<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

/**
 * One versioned schema change. Migrations are listed explicitly, in order, in
 * a Migrations object; the list key is the migration's permanent ID.
 */
interface Migration
{
    public function up(Schema $schema): void;
}
