<?php

declare(strict_types=1);

namespace Shirakaba\Core\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Header;
use Shirakaba\Core\Extraction\Path;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\NoContent;
use Shirakaba\Core\Http\ResponseValue;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Internal\HandlerInvoker;
use Shirakaba\Core\InvalidApplication;
use Shirakaba\Core\Tests\Fixtures\JsonBody;

/**
 * The boot-time errors listed in design/04-routing-and-handlers.md.
 */
#[CoversClass(HandlerInvoker::class)]
#[CoversClass(AppBuilder::class)]
final class BuildErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure, string}>
     */
    public static function invalidHandlers(): iterable
    {
        yield 'no way to resolve' => [static fn (int $id): Text => new Text(''), 'add an extractor attribute'];
        yield 'two extractors' => [static fn (#[Path]
            #[Query] int $id): Text => new Text(''), 'only one extractor attribute'];
        yield 'variadic' => [static fn (#[Query] string ...$tags): Text => new Text(''), 'variadic'];
        yield 'by reference' => [
            static function (#[Query] string &$tag): Text {
                return new Text('');
            },
            'by-reference',
        ];
        yield 'union type' => [static fn (#[Path] int|string $id): Text => new Text(''), 'needs a single declared type'];
        yield 'untyped' => [static fn (#[Path] $id): Text => new Text(''), 'needs a single declared type'];
        yield 'unsupported type' => [static fn (#[Path] array $id): Text => new Text(''), 'does not support the type array'];
        yield 'unsupported class' => [static fn (#[Query] \SplStack $at): Text => new Text(''), 'does not support the type SplStack'];
        yield 'unknown path name' => [static fn (#[Path('user')] int $id): Text => new Text(''), 'has no parameter "{user}"'];
        yield 'two body extractors' => [static fn (#[JsonBody] string $a, #[JsonBody] string $b): Text => new Text(''), 'only one body extractor'];
        yield 'missing return type' => [static fn () => new Text(''), 'must declare a return type'];
        yield 'array return type' => [static fn (): array => [], 'must declare a return type'];
        yield 'nullable return type' => [static fn (): ?Text => random_int(0, 1) === 1 ? new Text('') : null, 'must declare a return type'];
        yield 'mixed return type' => [static fn (): mixed => null, 'must declare a return type'];
    }

    #[DataProvider('invalidHandlers')]
    public function testRejectsInvalidHandlers(Closure $handler, string $message): void
    {
        $app = new AppBuilder();
        $app->get('/{id}', $handler);

        $this->expectException(InvalidApplication::class);
        $this->expectExceptionMessage($message);
        $app->build();
    }

    public function testErrorNamesTheRouteAndParameter(): void
    {
        $app = new AppBuilder();
        $app->get('/users/{id}', static fn (int $id): Text => new Text(''));

        $this->expectExceptionMessage('Route "GET /users/{id}", parameter $id:');
        $app->build();
    }

    /**
     * @return iterable<string, array{Closure}>
     */
    public static function validReturnTypes(): iterable
    {
        yield 'response' => [static fn (): ResponseInterface => new \Shirakaba\Http\Message\Response()];
        yield 'response value' => [static fn (): ResponseValue => new NoContent()];
        yield 'concrete value' => [static fn (): Text => new Text('')];
        yield 'union' => [static fn (): Text|NoContent => random_int(0, 1) === 1 ? new Text('') : new NoContent()];
        yield 'body extractor last' => [static fn (#[Header('X-A')] string $a, #[JsonBody] string $body): Text => new Text('')];
        yield 'body extractor first' => [static fn (#[JsonBody] string $body, #[Header('X-A')] string $a): Text => new Text('')];
    }

    #[DataProvider('validReturnTypes')]
    public function testAcceptsValidHandlers(Closure $handler): void
    {
        $this->expectNotToPerformAssertions();
        $app = new AppBuilder();
        $app->post('/', $handler);

        $app->build();
    }
}
