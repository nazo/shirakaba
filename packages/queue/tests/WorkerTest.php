<?php

declare(strict_types=1);

namespace Shirakaba\Queue\Tests;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Testing\ConsoleTester;
use Shirakaba\Core\Observe\Event;
use Shirakaba\Core\Observe\Observation;
use Shirakaba\Core\Observe\Observer;
use Shirakaba\Core\Observe\RequestSummary;
use Shirakaba\Core\Observe\RouteSummary;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Database\Connection;
use Shirakaba\Database\Database;
use Shirakaba\Migrations\Migrator;
use Shirakaba\OpenTelemetry\TracingObserver;
use Shirakaba\Queue\CreateJobsTable;
use Shirakaba\Queue\DatabaseQueue;
use Shirakaba\Queue\JobRunner;
use Shirakaba\Queue\Jobs;
use Shirakaba\Queue\QueueCommands;
use Shirakaba\Queue\Tests\Fixtures\SendWelcome;
use Shirakaba\Queue\Worker;
use RuntimeException;
use Throwable;
use Shirakaba\DatabaseBridge\RequestDatabase;

#[CoversClass(Worker::class)]
#[CoversClass(QueueCommands::class)]
#[CoversClass(ScopedWork::class)]
final class WorkerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/shirakaba-worker-' . bin2hex(random_bytes(4)) . '.sqlite';
        new Migrator(new Database(fn (): PDO => new PDO('sqlite:' . $this->file))->connect(), ['jobs' => new CreateJobsTable()])->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function database(): Database
    {
        return new Database(fn (): PDO => new PDO('sqlite:' . $this->file));
    }

    public function testEachJobRunsInItsOwnObservedScope(): void
    {
        $database = $this->database();
        $events = new ArrayObject();
        $observer = new class ($events) implements Observer {
            /** @param ArrayObject<int, string> $events */
            public function __construct(private ArrayObject $events) {}

            public function begin(RequestSummary $request): Observation
            {
                $this->events->append('begin ' . $request->method);

                return new class ($this->events) implements Observation {
                    /** @param ArrayObject<int, string> $events */
                    public function __construct(private ArrayObject $events) {}

                    public function matched(RouteSummary $route): void
                    {
                        $this->events->append('matched ' . $route->template);
                    }

                    public function handlerFinished(): void {}

                    public function failed(Throwable $error): void
                    {
                        $this->events->append('failed ' . $error->getMessage());
                    }

                    public function cleanupFailed(Throwable $error): void {}

                    public function recorded(Event $event): void
                    {
                        $this->events->append('recorded ' . $event->name());
                    }

                    public function responseReady(int $status): void
                    {
                        $this->events->append('status ' . $status);
                    }
                };
            }
        };
        $connections = new ArrayObject();
        $jobs = new Jobs(new DatabaseQueue($database));
        $jobs->handle(SendWelcome::class, static function (SendWelcome $job, RequestScope $scope) use ($database, $connections): void {
            $connection = RequestDatabase::connection($database, $scope);
            $connection->reader()->fetchValue('SELECT 1');
            $connections->append(spl_object_id($connection));
            if ($job->userId === 2) {
                throw new RuntimeException('bounce');
            }
        }, name: 'mail.welcome');
        $writer = $database->connect()->writer();
        $jobs->push(new SendWelcome(1, 'a@example.com'), $writer);
        $jobs->push(new SendWelcome(2, 'b@example.com'), $writer, new \Shirakaba\Queue\PushOptions(maxAttempts: 1));

        self::assertSame(2, new JobRunner($jobs, observers: [$observer])->runPending());
        self::assertSame([
            'begin JOB', 'matched mail.welcome', 'recorded db.connection', 'recorded db.statement', 'status 200',
            'begin JOB', 'matched mail.welcome', 'recorded db.connection', 'recorded db.statement', 'failed bounce', 'status 500',
        ], $events->getArrayCopy());
        self::assertCount(2, array_unique($connections->getArrayCopy()), 'Each job leased its own connection.');
    }

    public function testTheJobSpanContinuesTheRequestThatPushedIt(): void
    {
        $spans = new ArrayObject();
        $provider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($spans)));
        $tracer = $provider->getTracer('test');
        $database = $this->database();
        $jobs = new Jobs(new DatabaseQueue($database));
        $jobs->handle(SendWelcome::class, static function (): void {}, name: 'mail.welcome');

        $request = $tracer->spanBuilder('POST /users')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
        $active = $request->activate();
        try {
            $jobs->push(new SendWelcome(1, 'a@example.com'), $database->connect()->writer());
        } finally {
            $active->detach();
            $request->end();
        }
        new JobRunner($jobs, observers: [new TracingObserver($tracer)])->runPending();

        $pushed = $spans[0] ?? null;
        $job = $spans[1] ?? null;
        self::assertInstanceOf(ImmutableSpan::class, $pushed);
        self::assertInstanceOf(ImmutableSpan::class, $job);
        self::assertSame('JOB mail.welcome', $job->getName());
        self::assertSame($pushed->getTraceId(), $job->getTraceId());
        self::assertSame($pushed->getSpanId(), $job->getParentSpanId());
    }

    public function testCommandsWorkAndManageFailedJobs(): void
    {
        $database = $this->database();
        $queue = new DatabaseQueue($database);
        $jobs = new Jobs($queue);
        $jobs->handle(SendWelcome::class, static function (SendWelcome $job): void {
            if ($job->userId === 2) {
                throw new RuntimeException('bounce');
            }
        }, name: 'mail.welcome');
        $writer = $database->connect()->writer();
        $jobs->push(new SendWelcome(1, 'a@example.com'), $writer);
        $jobs->push(new SendWelcome(2, 'b@example.com'), $writer, new \Shirakaba\Queue\PushOptions(maxAttempts: 1));
        $cli = new ConsoleBuilder('app');
        QueueCommands::register($cli, static fn (): JobRunner => new JobRunner($jobs), static fn (): DatabaseQueue => $queue);
        $tester = new ConsoleTester($cli->build());

        $work = $tester->run('queue:work', '--stop-when-empty');
        self::assertSame(0, $work->exitCode);
        self::assertSame("succeeded mail.welcome #1 (attempt 1)\nfailed mail.welcome #2 (attempt 1): bounce\n", $work->output);
        self::assertSame("Worker stopped: queue empty (2 jobs).\n", $work->errors);
        self::assertStringContainsString('mail.welcome  attempts=1  RuntimeException: bounce', $tester->run('queue:failed')->output);
        self::assertSame("Job 2 will run again.\n", $tester->run('queue:retry', '2')->output);
        self::assertSame(1, $tester->run('queue:forget', '2')->exitCode, 'Only failed jobs can be forgotten.');
        $tester->run('queue:work', '--stop-when-empty');
        self::assertSame("Job 2 deleted.\n", $tester->run('queue:forget', '2')->output);
        self::assertSame("No failed jobs.\n", $tester->run('queue:failed')->output);
    }

    public function testSigtermFinishesTheCurrentJobAndExitsCleanly(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs ext-pcntl and ext-posix.');
        }
        $database = $this->database();
        $jobs = new Jobs(new DatabaseQueue($database));
        $jobs->handle(SendWelcome::class, static function (): void {}, name: 'mail.welcome');
        $writer = $database->connect()->writer();
        $jobs->push(new SendWelcome(1, 'a@example.com'), $writer);
        $jobs->push(new SendWelcome(2, 'b@example.com'), $writer);
        $log = $this->file . '.log';

        $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/worker.php', $this->file, $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 5;
            while (!str_contains((string) @file_get_contents($log), 'start 1') && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $pid = proc_get_status($process)['pid'];
            posix_kill($pid, SIGTERM);
            $stderr = stream_get_contents($pipes[2] ?? STDERR);
            $status = proc_close($process);

            self::assertSame(0, $status);
            self::assertSame("start 1\nend 1\n", file_get_contents($log), 'The running job finished; the next one did not start.');
            self::assertStringContainsString('Worker stopped: cancelled (1 jobs).', (string) $stderr);
        } finally {
            @unlink($log);
        }
    }
}
