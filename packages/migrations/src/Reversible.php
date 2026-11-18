<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

/**
 * A migration that rollback can undo.
 */
interface Reversible extends Migration
{
    public function down(Schema $schema): void;
}
