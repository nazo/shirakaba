# Commands, background jobs and scheduled tasks

## Command-line applications

Tools, queue workers and applications' own commands (imports, maintenance) share one small console
package, `shirakaba/console`, independent of the HTTP pipeline.

- Commands are typed callables, like handlers. Arguments and options are read from parameters with
  attributes:

  ```php
  $cli = new ConsoleBuilder('app');
  $cli->command('users:import', static function (
      #[Argument] string $file,
      #[Option] bool $dryRun = false,
      #[Option] int $batch = 500,
      Output $output,
  ): int { ... })->describe('Imports users from a CSV file.');
  $cli->build()->run($argv);
  ```

- Types are parsed strictly with the rules of request parameters (`int`, `bool`, backed enums,
  `DateTimeImmutable`, `LocalDate`); a bad value is a usage error naming the argument. Help text and
  usage come from the same metadata.
- Commands are planned when the console is built, so an unmappable parameter fails at `build()`, not
  when the command first runs.
- Exit codes are part of the contract: 0 success, 1 failure reported by the command, 2 usage error,
  130 interrupted. Uncaught exceptions print their class and message (a trace only with `--verbose`)
  and exit with 1.
- A command can ask for a `Cancellation` that is requested on SIGINT or SIGTERM (with `ext-pcntl`),
  so workers stop between jobs instead of being killed in the middle of one.
- Output goes through a small `Output` object; there is no formatting framework. Tests capture it
  with a test runner.
- Packages register their commands into the application's console (`MigrationCommands::register()`,
  `OpenApiCommand::register()`), and single-command tools declare a default command.

A general-purpose console library was not used: it is large, configured through classes and
inheritance, and duplicates what parameter types and attributes already say.

## Background jobs

Work that should not delay a response (sending mail, calling slow APIs, recomputing data) runs outside
the request. The usual failures are a job enqueued for a transaction that rolls back, a job lost
because the broker accepted it before the transaction committed, and a job run twice after a worker
crash. `shirakaba/queue` is designed around them.

- A `Queue` interface (push, reserve, acknowledge, release, fail) with a database driver
  (`DatabaseQueue`) and a Redis driver (`shirakaba/queue-redis`).
- **Jobs are data, handlers are code.** A job is a `#[JsonDto]` readonly class stored as JSON under a
  stable type name, never `serialize()`d. A handler is a callable registered for the job class with
  explicitly wired dependencies:

  ```php
  $jobs = new Jobs($queue);
  $jobs->handle(SendWelcomeMail::class, $mailer->sendWelcome(...), name: 'mail.welcome');
  $jobs->push(new SendWelcomeMail($user->id), $writer);
  ```

  A job of an unknown type is failed, never run.
- **Enqueueing joins the transaction.** With the database driver the job row is inserted in the
  caller's transaction, so it exists exactly when the business data does (a transactional outbox).
  The Redis driver pushes after commit with `afterCommit()`; a job can then be lost if the process
  dies between commit and push, which is the price of keeping the queue's writes off the database.
- **At-least-once delivery.** A worker reserves a job with a lease, using `FOR UPDATE SKIP LOCKED` on
  MySQL and PostgreSQL and a conditional update on SQLite (one Lua script per operation on Redis). A
  job whose lease expires is delivered again, so handlers must be idempotent; the guide shows how with
  a unique key. Exactly-once delivery is not achievable across a crash without idempotent handlers.
- **Retries** are counted per job with a bounded number of attempts and backoff with jitter from the
  PSR-20 clock. After the last attempt, or when a handler throws `JobFailed` for a permanent failure,
  the job is marked failed and kept for inspection and manual retry.
- **Delays and uniqueness.** Jobs can become available at a given instant; an optional unique key
  prevents duplicate pending jobs.
- **Workers are console commands.** `queue:work` reserves and runs jobs one at a time, stops between
  jobs on SIGTERM, and restarts itself after a number of jobs or an amount of memory. Each job runs in
  its own scope, like a request, with its own connection lease and cleanup, so nothing leaks between
  jobs. On PostgreSQL, idle workers can be woken with NOTIFY instead of polling.
- **Observability.** Jobs are reported to the same observers as requests (reserved, succeeded,
  retried, failed, with durations), and traces continue from the request that enqueued the job.
- **Testing.** An in-memory queue and a helper that runs pending jobs synchronously.

Running work after the response is sent (`fastcgi_finish_request()`, after-response hooks) was not
chosen: it has no retries, is lost on a crash and ties the work to the web process. A broker as the
only driver was not chosen either: it needs infrastructure many applications do not have and loses
the transactional outbox. Batches (run when every job of a group finished) and chains (run in order)
are not implemented yet.

## Scheduled tasks

Recurring work (session cleanup, pruning, reports) needs a schedule. System cron can call commands
directly, but then the schedule lives outside the application, is written per server and runs once
per server.

- The schedule is PHP code: `Schedule::job($name, $cron, $makeJob)` with five-field cron expressions
  (plus `@daily` and the like) evaluated in the schedule's time zone with the PSR-20 clock.
- System cron runs one command, `schedule:run`, every minute on every server. It pushes the job of each
  task due in the current minute and does not run the work itself, so the work gets the queue's
  retries, leases and observation.
- Each task runs once per minute slot however many servers call it: a row `(task, slot)` in
  `shirakaba_schedule_runs` is inserted in the same transaction as the job is pushed. A second server's
  insert fails on the key and it skips the task; a failed push rolls the claim back.
- Missed slots are not caught up. Work that must not be skipped checks what it has done itself (for
  example "report every day not yet reported"). Overlapping runs are prevented with the job's unique
  key.

## Redis

Sessions and the queue can use Redis or a compatible server (Valkey, ElastiCache). The `redis`
extension is not available everywhere, and a client library would be a third-party dependency, so
`shirakaba/redis` is a small client of its own:

- RESP2 over `stream_socket_client`, `redis://` and `rediss://` URLs (TLS with verified certificates),
  `AUTH` with a password or a user and password, `SELECT` of a database, timeouts on connecting and on
  every reply, and bounds on reply sizes;
- `command(...$arguments)` sends one command; `eval()` runs a Lua script, which is how the queue and
  the session store make their changes atomic;
- only stateless commands are offered (no `MULTI`, `WATCH`, `SUBSCRIBE`, or `SELECT` after
  connecting), so nothing a command leaves behind reaches the next request in a worker runtime. After
  an error that may have left a reply unread, the connection is closed and the next command
  reconnects.

It covers the commands Shirakaba uses, not pub/sub, clusters or RESP3.
