<?php

declare(strict_types=1);

// The application every runtime is tested with. RUNTIME selects the runtime.

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Form\FormBody;
use Shirakaba\Http\Message\Response;
use Shirakaba\Runtime\FrankenPhp\FrankenPhpRuntime;
use Shirakaba\Runtime\Fpm\FpmRuntime;
use Shirakaba\Runtime\RoadRunner\RoadRunnerRuntime;
use Shirakaba\Runtime\Swoole\SwooleRuntime;
use Shirakaba\Streaming\Streamed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/Upload.php';

$app = new AppBuilder();
$app->get('/hello', static fn (#[Query] string $name = 'world'): Text => new Text('hello ' . $name));
$app->get('/cookies', static fn (): ResponseInterface => new Response(204, ['Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/']]));
$app->post('/echo', static fn (ServerRequestInterface $request): Text => new Text((string) $request->getBody()));
$app->post('/upload', static fn (#[FormBody] Upload $form): Text => new Text(sprintf('%s %d', $form->file->getClientFilename(), $form->file->getSize())));
$app->get('/stream', static fn (): Streamed => new Streamed((static function (): Generator {
    foreach (['one', 'two', 'three'] as $chunk) {
        yield $chunk . "\n";
    }
})(), 'text/plain'));
// Streams slowly; the test disconnects early and reads the marker file the cleanup writes.
$app->get('/endless', static function (RequestScope $scope, #[Query] string $token): Streamed {
    $marker = sys_get_temp_dir() . '/shirakaba-disconnect-' . (preg_match('/^[a-f0-9]{16}$/D', $token) === 1 ? $token : 'invalid');
    $cancellation = $scope->cancellation();
    $scope->defer(static function () use ($marker, $cancellation): void {
        file_put_contents($marker, $cancellation->isRequested() ? 'cancelled' : 'finished', FILE_APPEND);
    });

    return new Streamed((static function () use ($cancellation): Generator {
        for ($i = 0; $i < 500 && !$cancellation->isRequested(); $i++) {
            yield str_repeat('x', 1024) . "\n";
            usleep(10_000);
        }
    })(), 'text/plain');
});
$app->get('/scope', static function (RequestScope $scope): Text {
    // A leak between requests would make a previous request's value visible here.
    $seen = $scope->find(stdClass::class);
    $scope->set(new stdClass());

    return new Text($seen === null ? 'clean' : 'leaked');
});

// With a reused connection, a request that changes its session and leaves a
// transaction open must not affect the next one.
$database = new Shirakaba\Database\Database(static fn (): PDO => PDO::connect('sqlite:' . sys_get_temp_dir() . '/shirakaba-conformance-' . (is_string(getenv('RUNTIME')) ? getenv('RUNTIME') : 'fpm') . '.sqlite'), reuse: true);
$app->middleware(new Shirakaba\DatabaseBridge\DatabaseMiddleware($database));
$app->post('/database', static function (#[Shirakaba\DatabaseBridge\Db] Shirakaba\Database\Writer $db, RequestScope $scope): Text {
    $db->execute('CREATE TABLE IF NOT EXISTS conformance_rows (id INTEGER PRIMARY KEY)');
    $pdo = Shirakaba\DatabaseBridge\RequestDatabase::connection($scope->get(Shirakaba\Database\Database::class), $scope)->pdo;
    $clean = !$pdo->inTransaction()
        && $db->fetchValue('PRAGMA foreign_keys') === 1
        && $db->fetchValue('SELECT COUNT(*) FROM conformance_rows') === 0;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    $db->execute('INSERT INTO conformance_rows DEFAULT VALUES');

    return new Text($clean ? 'clean' : 'leaked');
});

// Counts requests served by this process: grows only when a worker keeps the application.
$served = new ArrayObject(['count' => 0]);
$mode = match (getenv('RUNTIME')) {
    'frankenphp' => FrankenPhpRuntime::inWorkerMode() ? 'worker' : 'classic',
    'roadrunner', 'swoole' => 'worker',
    default => 'classic',
};
$app->get('/process', static function () use ($served, $mode): Text {
    $served['count'] = (is_int($served['count']) ? $served['count'] : 0) + 1;

    return new Text(sprintf('%d %s', $served['count'], $mode));
});

$application = $app->build();
match (getenv('RUNTIME')) {
    'frankenphp' => new FrankenPhpRuntime()->run($application),
    'roadrunner' => new RoadRunnerRuntime()->run($application),
    'swoole' => (static function () use ($application): void {
        // One worker, so the request counter of /process sees every request. With coroutine
        // hooks, blocking calls such as usleep() let the server notice a disconnect.
        $server = new Swoole\Http\Server('127.0.0.1', (int) getenv('SWOOLE_PORT'));
        $server->set(['worker_num' => 1, 'hook_flags' => SWOOLE_HOOK_ALL, 'log_level' => SWOOLE_LOG_WARNING]);
        new SwooleRuntime($server)->run($application);
    })(),
    default => new FpmRuntime()->run($application),
};
