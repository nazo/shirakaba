<?php

declare(strict_types=1);

/*
 * Benchmarks of the request path. Run with opcache, as in production:
 *
 *   composer bench
 *
 * Each scenario runs for about a second after a warm-up; the median of five rounds is
 * reported. Numbers are only comparable on the same machine and PHP build.
 */

use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\RouteGroup;
use Shirakaba\Examples\Tasks\TaskApi;
use Shirakaba\Examples\Tasks\TaskSchema;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\Json\Json;
use Shirakaba\Json\JsonBody;
use Shirakaba\Json\Tests\Fixtures\CreateUser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @param Closure(): mixed $operation
 * @return float microseconds per operation (median of rounds)
 */
function measure(Closure $operation): float
{
    for ($i = 0; $i < 200; $i++) {
        $operation();
    }
    $rounds = [];
    for ($round = 0; $round < 5; $round++) {
        $iterations = 0;
        $start = hrtime(true);
        do {
            for ($i = 0; $i < 100; $i++) {
                $operation();
            }
            $iterations += 100;
            $elapsed = hrtime(true) - $start;
        } while ($elapsed < 200_000_000);
        $rounds[] = ($elapsed / $iterations) / 1000;
    }
    sort($rounds);

    return $rounds[2] ?? 0.0;
}

function manyRoutes(int $count): AppBuilder
{
    $app = new AppBuilder();
    for ($i = 0; $i < $count; $i++) {
        $app->group('/resource' . $i, static function (RouteGroup $group): void {
            $group->get('', static fn (): Text => new Text('list'));
            $group->get('/{id}', static fn (#[Path] int $id): Text => new Text('show'));
            $group->post('/{id}/archive', static fn (#[Path] int $id): Text => new Text('archive'));
        });
    }

    return $app;
}

$results = [];

$small = new AppBuilder();
$small->get('/hello', static fn (): Text => new Text('hello'));
$hello = $small->build();
$request = new ServerRequest('GET', 'http://localhost/hello');
$results['Minimal request (one route, Text)'] = measure(static fn () => $hello->handle($request));

$large = manyRoutes(100)->build();
$last = new ServerRequest('GET', 'http://localhost/resource99/42');
$results['Routing: last of 300 routes, #[Path] int'] = measure(static fn () => $large->handle($last));
$missing = new ServerRequest('GET', 'http://localhost/nowhere');
$results['Routing: 404 among 300 routes (Problem Details)'] = measure(static fn () => $large->handle($missing));

$query = new AppBuilder();
$query->get('/items', static fn (#[Query] int $page = 1, #[Query] string $sort = 'id', #[Query] bool $desc = false): Text => new Text('items'));
$queryApp = $query->build();
$queryRequest = new ServerRequest('GET', 'http://localhost/items?page=3&sort=name&desc=true');
$results['Extraction: three query parameters'] = measure(static fn () => $queryApp->handle($queryRequest));

$json = new AppBuilder();
$json->post('/users', static fn (#[JsonBody] CreateUser $input): Json => new Json(['name' => $input->name], 201));
$jsonApp = $json->build();
$body = '{"name":"Alice","email":"alice@example.com","address":{"city":"Tokyo","zip":"100-0001"},"age":30,"tags":["a","b"]}';
$results['JSON: nested DTO with constraints, JSON response'] = measure(static function () use ($jsonApp, $body) {
    return $jsonApp->handle(new ServerRequest('POST', 'http://localhost/users', ['Content-Type' => 'application/json'], $body));
});

$tasks = TaskApi::builder(TaskSchema::database(':memory:'));
$results['Build: example task API (per request under PHP-FPM)'] = measure(static fn () => $tasks->build());
$results['Build: 300 routes'] = measure(static fn () => manyRoutes(100)->build());
$results['Build: 300 routes, lazy (PHP-FPM)'] = measure(static fn () => manyRoutes(100)->build(lazy: true));
$results['Build: example task API, lazy (PHP-FPM)'] = measure(static fn () => $tasks->build(lazy: true));
$lazyTasks = TaskApi::builder(TaskSchema::database(':memory:'));
$listRequest = new ServerRequest('GET', 'http://localhost/tasks');
$results['Lazy build + one request (example, PHP-FPM)'] = measure(static fn () => $lazyTasks->build(lazy: true)->handle($listRequest));

$ormDatabase = new Shirakaba\Database\Database(static fn (): PDO => new PDO('sqlite::memory:'));
$ormDb = Shirakaba\Orm\Tests\Fixtures\Schema::create($ormDatabase)->writer();
$ormAuthor = Shirakaba\Orm\Entities::insert(new Shirakaba\Orm\Tests\Fixtures\Author('Ada', 'ada@example.com'), $ormDb);
$ormPosts = [];
for ($i = 0; $i < 100; $i++) {
    $ormPosts[] = new Shirakaba\Orm\Tests\Fixtures\Post(
        new Shirakaba\Orm\Tests\Fixtures\AuthorId($ormAuthor->id ?? 1),
        'Post ' . $i,
        Shirakaba\Orm\Tests\Fixtures\PostStatus::Published,
        meta: ['n' => $i],
    );
}
Shirakaba\Orm\Entities::insertAll($ormPosts, $ormDb);
$ormQuery = Shirakaba\Orm\Entities::query(Shirakaba\Orm\Tests\Fixtures\Post::class)->orderBy('id');
$results['ORM: 100 entities from SQLite (enum, JSON, dates, value object)'] = measure(static fn () => $ormQuery->fetch($ormDb));
$results['ORM: same rows as arrays (Select::fetchAll)'] = measure(static fn () => $ormQuery->select()->fetchAll($ormDb));

$width = max(array_map(strlen(...), array_keys($results)));
$opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
$jit = ini_get('opcache.jit');
printf(
    "PHP %s, opcache %s, JIT %s, %s\n\n",
    PHP_VERSION,
    is_array($opcache) && ($opcache['opcache_enabled'] ?? false) === true ? 'on' : 'off',
    $jit === false || $jit === '' ? 'off' : $jit,
    php_uname('m'),
);
printf("| %-{$width}s | µs/op |\n|%s|------:|\n", 'Scenario', str_repeat('-', $width + 2));
foreach ($results as $name => $microseconds) {
    printf("| %-{$width}s | %5.1f |\n", $name, $microseconds);
}
