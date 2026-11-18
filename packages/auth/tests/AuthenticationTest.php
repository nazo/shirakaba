<?php

declare(strict_types=1);

namespace Shirakaba\Auth\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Auth\Authenticated;
use Shirakaba\Auth\Authentication;
use Shirakaba\Auth\BearerAuthentication;
use Shirakaba\Auth\TokenVerifier;
use Shirakaba\Auth\Tests\Fixtures\User;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\ParameterDescription;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Scope\RequestScope;
use Shirakaba\Core\Testing\TestClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(BearerAuthentication::class)]
#[CoversClass(Authenticated::class)]
#[CoversClass(Authentication::class)]
final class AuthenticationTest extends TestCase
{
    private static function app(): AppBuilder
    {
        $verifier = new class implements TokenVerifier {
            public function verify(string $token, RequestScope $scope): ?object
            {
                return match ($token) {
                    'good' => new User('u1'),
                    // Stands for a token looked up through a request-scoped service.
                    'stored' => $scope->find(User::class),
                    'robot' => new \stdClass(),
                    default => null,
                };
            }
        };
        $app = new AppBuilder();
        $app->middleware(
            // Registers a request-scoped value before authentication, as DatabaseMiddleware does.
            new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    RequestScope::of($request)->set(new User('stored-user'));

                    return $handler->handle($request);
                }
            },
            new BearerAuthentication($verifier),
        );
        $app->get('/me', static fn (#[Authenticated] User $user): Text => new Text('hello ' . $user->id));
        $app->get('/maybe', static fn (#[Authenticated] ?User $user): Text => new Text($user === null ? 'anonymous' : $user->id));

        return $app;
    }

    public function testAuthenticatedRequest(): void
    {
        self::assertSame('hello u1', (string) new TestClient(self::app()->build())->get('/me', ['Authorization' => 'Bearer good'])->getBody());
    }

    public function testVerifierReadsTheRequestScope(): void
    {
        self::assertSame('hello stored-user', (string) new TestClient(self::app()->build())->get('/me', ['Authorization' => 'Bearer stored'])->getBody());
    }

    public function testChallengeForCustomMiddleware(): void
    {
        self::assertSame('Bearer realm="a\\"b"', BearerAuthentication::challenge('a"b'));
        self::assertSame('Bearer realm="api", error="invalid_token"', BearerAuthentication::challenge('api', 'invalid_token'));
    }

    public function testMissingCredentialsOnProtectedRoute(): void
    {
        $response = new TestClient(self::app()->build())->get('/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="api"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testInvalidTokenIsRejectedEvenOnOptionalRoutes(): void
    {
        $response = new TestClient(self::app()->build())->get('/maybe', ['Authorization' => 'Bearer bad']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="api", error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testMalformedHeader(): void
    {
        $response = new TestClient(self::app()->build())->get('/me', ['Authorization' => 'Basic dXNlcjpwYXNz']);

        self::assertSame('Bearer realm="api", error="invalid_request"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testOptionalAuthentication(): void
    {
        self::assertSame('anonymous', (string) new TestClient(self::app()->build())->get('/maybe')->getBody());
    }

    public function testPrincipalOfAnotherTypeIsForbidden(): void
    {
        self::assertSame(403, new TestClient(self::app()->build())->get('/me', ['Authorization' => 'Bearer robot'])->getStatusCode());
    }

    public function testDescribesItselfAsBearerSecurity(): void
    {
        $routes = self::app()->describe()->routes;
        $required = ($routes[0] ?? null)?->parameters[0] ?? null;
        $optional = ($routes[1] ?? null)?->parameters[0] ?? null;
        self::assertInstanceOf(ParameterDescription::class, $required);
        self::assertInstanceOf(ParameterDescription::class, $optional);

        self::assertSame(Authenticated::SOURCE, $required->source);
        self::assertTrue($required->required);
        self::assertFalse($optional->required);
    }
}
