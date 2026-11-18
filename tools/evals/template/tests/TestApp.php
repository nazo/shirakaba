<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Background;
use App\Schema;
use PDO;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Database\Database;
use Shirakaba\Migrations\Migrator;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\Testing\InMemoryQueue;

/**
 * A migrated temporary SQLite database, an in-memory queue and a client for the application.
 */
final readonly class TestApp
{
    public function __construct(
        public Database $database,
        public Jobs $jobs,
        public InMemoryQueue $queue,
        public TestClient $client,
    ) {}

    public static function create(): self
    {
        $file = tempnam(sys_get_temp_dir(), 'app-test-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create a temporary database.');
        }
        $database = new Database(static fn (): PDO => PDO::connect('sqlite:' . $file));
        Migrator::for(Schema::migrations($database))->migrate();
        $queue = new InMemoryQueue();
        $jobs = new Jobs($queue);
        Background::register($jobs, $database);

        return new self($database, $jobs, $queue, new TestClient(App::builder($database, $jobs)->build()));
    }
}
