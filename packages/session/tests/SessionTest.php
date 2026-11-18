<?php

declare(strict_types=1);

namespace Shirakaba\Session\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Time\FrozenClock;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Http\NoContent;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Session\CsrfProtection;
use Shirakaba\Session\FileSessionStore;
use Shirakaba\Session\InMemorySessionStore;
use Shirakaba\Session\JsonData;
use Shirakaba\Session\Session;
use Shirakaba\Session\SessionMiddleware;
use Shirakaba\Session\SetCookie;
use Psr\Http\Message\ResponseInterface;
use Shirakaba\Core\Scope\Cancellation;
use Shirakaba\Core\Scope\ScopedWork;
use Shirakaba\Session\SessionStore;
use Shirakaba\Session\Testing\SessionStoreChecks;

#[CoversClass(Session::class)]
#[CoversClass(SessionMiddleware::class)]
#[CoversClass(SetCookie::class)]
#[CoversClass(InMemorySessionStore::class)]
#[CoversClass(FileSessionStore::class)]
#[CoversClass(JsonData::class)]
#[CoversClass(SessionStoreChecks::class)]
#[CoversClass(CsrfProtection::class)]
final class SessionTest extends TestCase
{
    private InMemorySessionStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore();
    }

    private function client(): TestClient
    {
        $app = new AppBuilder();
        $app->middleware(new SessionMiddleware($this->store), new CsrfProtection(['https://app.example.com']));
        $app->get('/count', static function (RequestScope $scope): Text {
            $session = Session::of($scope);
            $count = $session->get('count', 0);
            $session->set('count', is_int($count) ? $count + 1 : 1);

            $updated = $session->get('count');

            return new Text(is_int($updated) ? (string) $updated : '?');
        });
        $app->get('/peek', static fn (RequestScope $scope): Text => new Text(var_export(Session::of($scope)->flashed('notice'), true)));
        $app->post('/login', static function (RequestScope $scope): NoContent {
            $session = Session::of($scope);
            $session->regenerate();
            $session->set('user', 'alice');
            $session->flash('notice', 'Welcome');

            return new NoContent();
        });
        $app->post('/logout', static function (RequestScope $scope): NoContent {
            Session::of($scope)->invalidate();

            return new NoContent();
        });
        $app->get('/token', static fn (RequestScope $scope): Text => new Text(CsrfProtection::token(Session::of($scope))));

        return new TestClient($app->build());
    }

    private static function sessionId(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/^__Host-session=([^;]*)/', $cookie, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function cookie(?string $id): array
    {
        return $id === null ? [] : ['Cookie' => '__Host-session=' . $id, 'Sec-Fetch-Site' => 'same-origin'];
    }

    /**
     * Runs $work with a request scope, as SessionMiddleware calls a store.
     *
     * @template T
     * @param \Closure(RequestScope): T $work
     * @return T
     */
    private static function inScope(\Closure $work): mixed
    {
        $result = null;
        $error = ScopedWork::run([], 'TEST', 'store', new Cancellation(), static function (RequestScope $scope) use ($work, &$result): void {
            $result = $work($scope);
        });
        if ($error !== null) {
            throw $error;
        }

        /** @var T $result */
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function load(SessionStore $store, string $id): ?array
    {
        return self::inScope(static fn (RequestScope $scope): ?array => $store->load($scope, $id));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function save(SessionStore $store, string $id, array $data, int $lifetime): void
    {
        self::inScope(static fn (RequestScope $scope) => $store->save($scope, $id, $data, $lifetime));
    }

    private static function delete(SessionStore $store, string $id): void
    {
        self::inScope(static fn (RequestScope $scope) => $store->delete($scope, $id));
    }

    public function testPersistsAcrossRequests(): void
    {
        $client = $this->client();
        $first = $client->get('/count');
        $id = self::sessionId($first);

        self::assertNotNull($id);
        self::assertStringContainsString('; Secure; HttpOnly; SameSite=Lax', $first->getHeaderLine('Set-Cookie'));
        self::assertSame('2', (string) $client->get('/count', self::cookie($id))->getBody());
        self::assertSame('3', (string) $client->get('/count', self::cookie($id))->getBody());
        self::assertSame('1', (string) $client->get('/count')->getBody(), 'Another client has its own session.');
    }

    public function testUntouchedNewSessionSetsNoCookie(): void
    {
        self::assertFalse($this->client()->get('/peek')->hasHeader('Set-Cookie'));
    }

    public function testUnknownSessionIdsAreNotAdopted(): void
    {
        $planted = str_repeat('a', 43);
        $response = $this->client()->get('/count', self::cookie($planted));

        self::assertNotSame($planted, self::sessionId($response));
        self::assertNull(self::load($this->store, $planted));
    }

    public function testLoginRegeneratesTheIdAndFlashesForOneRequest(): void
    {
        $client = $this->client();
        $before = self::sessionId($client->get('/count'));
        $login = $client->post('/login', '', self::cookie($before));
        $after = self::sessionId($login);

        self::assertNotNull($after);
        self::assertNotSame($before, $after);
        self::assertNull(self::load($this->store, (string) $before), 'The old ID no longer works.');
        self::assertSame("'Welcome'", (string) $client->get('/peek', self::cookie($after))->getBody());
        self::assertSame('NULL', (string) $client->get('/peek', self::cookie($after))->getBody());
    }

    public function testLogoutDeletesTheSession(): void
    {
        $client = $this->client();
        $id = self::sessionId($client->get('/count'));
        $logout = $client->post('/logout', '', self::cookie($id));

        self::assertStringContainsString('__Host-session=; Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertNull(self::load($this->store, (string) $id));
    }

    public function testCsrfProtection(): void
    {
        $client = $this->client();
        $tokenResponse = $client->get('/token');
        $id = self::sessionId($tokenResponse);
        $token = (string) $tokenResponse->getBody();
        $cookie = ['Cookie' => '__Host-session=' . $id];

        self::assertSame(403, $client->post('/login', '', [...$cookie, 'Sec-Fetch-Site' => 'cross-site'])->getStatusCode());
        self::assertSame(403, $client->post('/login', '', [...$cookie, 'Origin' => 'https://evil.example'])->getStatusCode());
        self::assertSame(204, $client->post('/login', '', [...$cookie, 'Origin' => 'https://app.example.com'])->getStatusCode());
        self::assertSame(403, $client->post('/logout', '', $cookie)->getStatusCode(), 'No browser hints and no token.');
        self::assertSame(403, $client->post('/logout', '', [...$cookie, CsrfProtection::HEADER => 'wrong'])->getStatusCode());
    }

    public function testCsrfTokenAcceptedWithoutBrowserHints(): void
    {
        $client = $this->client();
        $tokenResponse = $client->get('/token');
        $cookie = ['Cookie' => '__Host-session=' . self::sessionId($tokenResponse)];

        self::assertSame(204, $client->post('/logout', '', [...$cookie, CsrfProtection::HEADER => (string) $tokenResponse->getBody()])->getStatusCode());
    }

    public function testLoginReplacesTheCsrfToken(): void
    {
        $client = $this->client();
        $tokenResponse = $client->get('/token');
        $before = (string) $tokenResponse->getBody();
        $login = $client->post('/login', '', ['Cookie' => '__Host-session=' . self::sessionId($tokenResponse), CsrfProtection::HEADER => $before]);
        $cookie = ['Cookie' => '__Host-session=' . self::sessionId($login)];

        self::assertSame(204, $login->getStatusCode());
        self::assertSame(403, $client->post('/logout', '', [...$cookie, CsrfProtection::HEADER => $before])->getStatusCode(), 'The pre-login token is gone.');
        $after = (string) $client->get('/token', $cookie)->getBody();
        self::assertNotSame($before, $after);
        self::assertSame(204, $client->post('/logout', '', [...$cookie, CsrfProtection::HEADER => $after])->getStatusCode());
    }

    public function testRegenerateRunsListeners(): void
    {
        $session = new Session(Session::newId(), ['token' => 'a', 'user' => 'alice'], false);
        $session->onRegenerate(static fn (Session $session) => $session->remove('token'));
        $session->regenerate();

        self::assertFalse($session->has('token'));
        self::assertTrue($session->has('user'));
    }

    public function testStoresPassTheChecks(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-18T00:00:00Z'));
        self::assertSame([], self::inScope(static fn (RequestScope $scope): array => SessionStoreChecks::run(new InMemorySessionStore($clock), $clock, $scope)));

        $directory = sys_get_temp_dir() . '/shirakaba-sessions-' . bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            self::assertSame([], self::inScope(static fn (RequestScope $scope): array => SessionStoreChecks::run(new FileSessionStore($directory, $clock), $clock, $scope)));
        } finally {
            $files = glob($directory . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir($directory);
        }
    }

    public function testExpiredSessionsAreGone(): void
    {
        $clock = new FrozenClock();
        $store = new InMemorySessionStore($clock);
        self::save($store, str_repeat('b', 43), ['a' => 1], 60);
        $clock->advance(59);
        self::assertSame(['a' => 1], self::load($store, str_repeat('b', 43)));
        $clock->advance(1);

        self::assertNull(self::load($store, str_repeat('b', 43)));
    }

    public function testFileStoreKeepsJsonAndRejectsBadIds(): void
    {
        $directory = sys_get_temp_dir() . '/shirakaba-sessions-' . bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            $store = new FileSessionStore($directory);
            $id = Session::newId();
            self::save($store, $id, ['user' => 'alice'], 60);

            self::assertSame(['user' => 'alice'], self::load($store, $id));
            self::assertStringStartsWith('{"expires":', (string) file_get_contents($directory . '/sess_' . $id . '.json'));
            self::assertSame(0600, (int) fileperms($directory . '/sess_' . $id . '.json') & 0777);
            self::delete($store, $id);
            self::assertNull(self::load($store, $id));

            $this->expectException(InvalidArgumentException::class);
            self::load($store, '../../etc/passwd');
        } finally {
            $files = glob($directory . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir($directory);
        }
    }

    public function testFileStoreCollectsGarbage(): void
    {
        $directory = sys_get_temp_dir() . '/shirakaba-sessions-' . bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            $clock = new FrozenClock(new \DateTimeImmutable());
            $store = new FileSessionStore($directory, $clock);
            $live = Session::newId();
            $expired = Session::newId();
            self::save($store, $live, ['user' => 'alice'], 120);
            self::save($store, $expired, ['user' => 'bob'], 60);
            $stale = $directory . '/sess_' . Session::newId() . '.json.0011223344556677.tmp';
            // Temporary files are judged by their modification time, written by the file system.
            touch($stale, $clock->now()->getTimestamp() - 7200);
            $clock->advance(60);

            self::assertSame(2, $store->collectGarbage());
            self::assertSame(['user' => 'alice'], self::load($store, $live));
            self::assertFileDoesNotExist($stale);
            self::assertFileDoesNotExist($directory . '/sess_' . $expired . '.json');
        } finally {
            $files = glob($directory . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir($directory);
        }
    }

    public function testCookieAttributes(): void
    {
        self::assertSame('theme=dark%20mode; Max-Age=60; Path=/; Secure; HttpOnly; SameSite=Strict', new SetCookie('theme', 'dark mode', 60, sameSite: 'Strict')->header());

        $this->expectException(InvalidArgumentException::class);
        new SetCookie('__Host-id', 'x', domain: 'example.com');
    }
}
