<?php

declare(strict_types=1);

namespace App;

use App\Migrations\CreateNotes;
use App\Migrations\CreateSentMails;
use App\Migrations\CreateUsers;
use Shirakaba\Database\Database;
use Shirakaba\Migrations\Migrations;
use Shirakaba\Queue\CreateJobsTable;

final readonly class Schema
{
    public static function migrations(Database $database): Migrations
    {
        return new Migrations(
            $database,
            [
                '2026_11_01_000000_create_users' => new CreateUsers(),
                '2026_11_01_000001_create_sent_mails' => new CreateSentMails(),
                '2026_11_01_000002_create_jobs' => new CreateJobsTable(),
                '2026_11_02_000000_create_notes' => new CreateNotes(),
            ],
            entities: self::entities(),
        );
    }

    /**
     * @return list<class-string>
     */
    public static function entities(): array
    {
        return [User::class, Note::class];
    }
}
