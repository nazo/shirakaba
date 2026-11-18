<?php

declare(strict_types=1);

namespace App\Migrations;

use Shirakaba\Migrations\Blueprint;
use Shirakaba\Migrations\Reversible;
use Shirakaba\Migrations\Schema;

/**
 * Welcome mails that were sent, one per user.
 */
final readonly class CreateSentMails implements Reversible
{
    public function up(Schema $schema): void
    {
        $schema->create('sent_mails', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unique();
            $table->string('kind', 50);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('sent_mails');
    }
}
