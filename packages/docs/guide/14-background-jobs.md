# Background jobs

`shirakaba/queue` runs work outside the request ([background work](../design/10-background-work.md)): sending mail, calling slow APIs,
recomputing data.

## Jobs and handlers

A job is a `#[JsonDto]` class; it is stored as JSON, never `serialize()`d. Handlers are
registered with a stable name, so renaming the class does not orphan waiting jobs:

```php
#[JsonDto]
final readonly class SendWelcome
{
    public function __construct(public int $userId) {}
}

$jobs = new Jobs(new DatabaseQueue($database));
$jobs->handle(SendWelcome::class, $mailer->sendWelcome(...), name: 'mail.welcome');
```

## Pushing in the transaction

```php
$writer->transaction(function (Writer $db) use ($jobs, $user): void {
    $user = Entities::insert($user, $db);
    $jobs->push(new SendWelcome($user->id), $db);
});
```

The database queue inserts the job in the same transaction, so a rolled-back sign-up never
sends mail and a committed one never loses its job. `PushOptions` sets the queue name, a
delay, a unique key (a second push while one is waiting does nothing) and the number of
attempts.

Add the table to your migrations: `'2026_11_12_create_jobs' => new CreateJobsTable()`.

## Delivery and retries

Delivery is at least once. A worker holds a job with a lease; if it crashes, the lease
expires and another worker runs the job again. Write handlers so that running twice is
harmless, for example by recording a unique key of the work done:

```php
public function sendWelcome(SendWelcome $job): void
{
    if (!Insert::into('sent_mails')->values(['key' => 'welcome-' . $job->userId])->onConflict(['key'])->execute($this->db)) {
        return; // already sent
    }
    // send
}
```

A handler that throws is retried with exponential backoff and jitter (`RetryPolicy`); after
the last attempt the job is kept as failed with the exception's class and message. Jobs of
unknown types and undecodable payloads are failed, never run.

## Handlers and request-scoped services

A handler receives the job and the `RequestScope` it runs in. Each job gets a fresh scope,
like a request, so request-scoped services are leased per job and released after it:

```php
$jobs->handle(SendWelcome::class, static function (SendWelcome $job, RequestScope $scope) use ($database, $mailer): void {
    $user = Entities::get(User::class, $job->userId, RequestDatabase::reader($database, $scope));
    $mailer->send($user->email, 'Welcome');
}, name: 'mail.welcome');
```

## Running workers

```php
$runner = new JobRunner($jobs, observers: [$logging, $tracing]);
QueueCommands::register($cli, static fn (): JobRunner => $runner, static fn (): DatabaseQueue => $queue);
```

```sh
app queue:work --queue=mail --queue=default --max-jobs=1000 --max-memory=256
app queue:failed
app queue:retry 42
app queue:forget 42
```

`queue:work` runs one job at a time. SIGTERM or SIGINT stops it after the current job, so a
deployment never kills a job halfway; `--max-jobs` and `--max-memory` make it exit so the
process manager starts a fresh one, as worker runtimes do after a number of requests. Run
it under systemd, Supervisor or as a container with a restart policy, and scale by running
more workers.

An idle worker on PostgreSQL does not poll: `DatabaseQueue` sends a NOTIFY when a job is
pushed (at commit), and the worker waits for it, so jobs start at once without `--sleep`
costing queries. `--rate=mail=5` keeps a worker to five jobs per second from the `mail` queue,
for providers with rate limits; each worker keeps its own limit, so divide by the number of
workers.

Observers see each job like a request: method `JOB`, the job's type as route, status 200
(succeeded), 503 (will be retried) or 500 (failed). The access log gets a line per job,
and the job's span is a child of the request that pushed it.

## A Redis queue

`shirakaba/queue-redis` keeps jobs in Redis instead of a table, for applications that keep the
queue's traffic off the database ([background work](../design/10-background-work.md)):

```php
$queue = new RedisQueue(RedisClient::fromUrl((string) getenv('REDIS_URL')), $clock);
$jobs = new Jobs($queue);
```

Each change is one Lua script, so workers never take the same job, and reservations carry a
lease and a token as with `DatabaseQueue`. The difference is pushing: the job reaches Redis
after the caller's transaction commits, so a rolled-back transaction pushes nothing, but a
process that dies between the commit and the push loses the job, and `push()` cannot tell yet
whether a job with the same unique key is waiting (it returns `true`; the duplicate is
dropped in Redis). Keep `DatabaseQueue` for jobs that must never be lost.

A handler that knows a job can never succeed throws `JobFailed`, which fails the job at once
instead of retrying it.

## Scheduled tasks

Recurring work is a schedule of jobs ([background work](../design/10-background-work.md)). Define it in PHP, add the runs table to
your migrations, and let system cron call one command every minute on every server:

```php
$schedule = new Schedule($jobs, new DateTimeZone('Asia/Tokyo'))
    ->job('sessions.gc', '*/15 * * * *', static fn (): object => new CollectSessions())
    ->job('reports.daily', '0 6 * * *', static fn (DateTimeImmutable $slot): object => new DailyReport($slot));

ScheduleCommands::register($cli, static fn (): Schedule => $schedule, static fn (): Writer => $database->connect()->writer());
// migrations: '2026_11_12_create_schedule_runs' => new CreateScheduleTable(),
// crontab:    * * * * * php bin/app schedule:run
```

Expressions have five fields (minute, hour, day, month, weekday 0-7) with `*`, ranges, lists
and steps, or `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly`, read in the schedule's
time zone. Each task is queued once per minute however many servers run `schedule:run`; a
minute that passed while nothing ran is skipped. `schedule:list` shows the next and last runs.
In tests, call `$schedule->run($writer, $frozenClock)` and advance the clock.

## Testing

`JobRunner::runPending()` runs due jobs synchronously. `InMemoryQueue` accepts jobs when
the pushing transaction commits and lists them with `pendingTypes()`.
