<?php

declare(strict_types=1);

namespace Shirakaba\Runtime\FrankenPhp\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\Runtime\FrankenPhp\FrankenPhpRuntime;
use Shirakaba\Sapi\SapiEmitter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Drives the worker loop with a fake frankenphp_handle_request(), since FrankenPHP itself
 * is exercised by the runtime conformance suite.
 */
#[CoversClass(FrankenPhpRuntime::class)]
final class FrankenPhpRuntimeTest extends TestCase
{
    /** @var list<string> */
    private array $output = [];

    /** @var list<string> */
    private array $statusLines = [];

    private function emitter(): SapiEmitter
    {
        return new SapiEmitter(
            function (string $line): void {
                if (str_starts_with($line, 'HTTP/')) {
                    $this->statusLines[] = $line;
                }
            },
            function (string $chunk): void {
                $this->output[] = $chunk;
            },
            static fn (): bool => false,
        );
    }

    /**
     * @param list<ServerRequestInterface|null> $requests null makes the factory fail as for a bad Host header
     * @return array{Closure(Closure(): void): bool, Closure(): ServerRequestInterface}
     */
    private static function worker(array $requests): array
    {
        $queue = new \ArrayIterator($requests);
        $current = null;

        return [
            static function (Closure $handler) use ($queue, &$current): bool {
                if (!$queue->valid()) {
                    return false;
                }
                $current = $queue->current();
                $queue->next();
                $handler();

                return true;
            },
            static function () use (&$current): ServerRequestInterface {
                return $current instanceof ServerRequestInterface ? $current : throw new \InvalidArgumentException('Invalid Host header.');
            },
        ];
    }

    public function testServesManyRequestsWithIsolatedScopes(): void
    {
        $app = new AppBuilder();
        $app->get('/whoami', static function (ServerRequestInterface $request, RequestScope $scope): Text {
            $seen = $scope->find(\stdClass::class);
            $marker = new \stdClass();
            $scope->set($marker);

            return new Text($request->getHeaderLine('X-User') . ($seen === null ? ' fresh' : ' leaked'));
        });
        [$handle, $factory] = self::worker([
            new ServerRequest('GET', 'http://localhost/whoami', ['X-User' => 'alice']),
            null,
            new ServerRequest('GET', 'http://localhost/whoami', ['X-User' => 'bob']),
        ]);

        new FrankenPhpRuntime($this->emitter(), 10, $handle, $factory)->run($app->build());

        self::assertSame(['HTTP/1.1 200 OK', 'HTTP/1.1 400 Bad Request', 'HTTP/1.1 200 OK'], $this->statusLines);
        self::assertSame(['alice fresh', 'Bad Request', 'bob fresh'], $this->output);
    }

    public function testStopsAfterMaxRequests(): void
    {
        $app = new AppBuilder();
        $app->get('/', static fn (): Text => new Text('ok'));
        $request = new ServerRequest('GET', 'http://localhost/');
        [$handle, $factory] = self::worker([$request, $request, $request]);

        new FrankenPhpRuntime($this->emitter(), 2, $handle, $factory)->run($app->build());

        self::assertCount(2, $this->output);
    }

    public function testInWorkerModeIsFalseOutsideFrankenPhp(): void
    {
        self::assertFalse(FrankenPhpRuntime::inWorkerMode());
    }
}
