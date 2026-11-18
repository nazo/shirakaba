<?php

declare(strict_types=1);

namespace App;

use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Database\Database;
use Shirakaba\Query\Insert;
use Shirakaba\Queue\Jobs;
use Shirakaba\DatabaseBridge\RequestDatabase;

final readonly class Background
{
    public static function register(Jobs $jobs, Database $database): void
    {
        $jobs->handle(SendWelcome::class, static function (SendWelcome $job, RequestScope $scope) use ($database): void {
            // The unique user_id makes a second run a no-op.
            Insert::into('sent_mails')
                ->values(['user_id' => $job->userId, 'kind' => 'welcome'])
                ->onConflict(['user_id'])
                ->execute(RequestDatabase::writer($database, $scope));
        }, name: 'mail.welcome');
    }
}
