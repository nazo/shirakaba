<?php

declare(strict_types=1);

namespace Shirakaba\Validation\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Extraction\Constraint;
use Shirakaba\Core\Extraction\Query;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Validation\Bounds;
use Shirakaba\Validation\Count;
use Shirakaba\Validation\DateRange;
use Shirakaba\Validation\Email;
use Shirakaba\Validation\Length;
use Shirakaba\Validation\NotBlank;
use Shirakaba\Validation\OneOf;
use Shirakaba\Validation\Pattern;
use Shirakaba\Validation\Range;

#[CoversClass(Length::class)]
#[CoversClass(Range::class)]
#[CoversClass(Count::class)]
#[CoversClass(Pattern::class)]
#[CoversClass(NotBlank::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(Email::class)]
#[CoversClass(Bounds::class)]
#[CoversClass(DateRange::class)]
final class ConstraintsTest extends TestCase
{
    /**
     * @return iterable<string, array{Constraint, mixed, string|null}>
     */
    public static function cases(): iterable
    {
        yield 'length ok' => [new Length(2, 3), 'äb', null];
        yield 'date in range' => [new DateRange('2000-01-01', '2000-12-31'), \Shirakaba\Time\LocalDate::of(2000, 12, 31), null];
        yield 'date too early' => [new DateRange('2000-01-01'), \Shirakaba\Time\LocalDate::of(1999, 12, 31), 'must not be before 2000-01-01'];
        yield 'instant within the last day' => [new DateRange(notAfter: '2000-12-31'), new \DateTimeImmutable('2000-12-31T23:59:59Z'), null];
        yield 'instant after the last day' => [new DateRange(notAfter: '2000-12-31'), new \DateTimeImmutable('2001-01-01T00:00:00Z'), 'must not be after 2000-12-31'];
        yield 'instant bound' => [new DateRange(notBefore: '2026-11-11T09:00:00+09:00'), new \DateTimeImmutable('2026-11-10T23:59:59Z'), 'must not be before 2026-11-11T09:00:00+09:00'];
        yield 'length counts code points' => [new Length(max: 2), 'äöü', 'must be at most 2 characters long'];
        yield 'length too short' => [new Length(min: 2), 'a', 'must be at least 2 characters long'];
        yield 'length invalid utf-8' => [new Length(min: 1), "\xff", 'must be valid UTF-8'];
        yield 'length ignores non-strings' => [new Length(min: 5), 3, null];
        yield 'range ok' => [new Range(1, 10), 10, null];
        yield 'range too small' => [new Range(min: 1), 0, 'must be at least 1'];
        yield 'range float' => [new Range(max: 1.5), 1.6, 'must be at most 1.5'];
        yield 'count ok' => [new Count(1, 2), ['a'], null];
        yield 'count too many' => [new Count(max: 1), ['a', 'b'], 'must have at most 1 items'];
        yield 'pattern ok' => [new Pattern('^[a-z0-9-]+$'), 'my-slug', null];
        yield 'pattern fails' => [new Pattern('^[a-z0-9-]+$', 'must be a slug'), 'My Slug', 'must be a slug'];
        yield 'pattern with slash' => [new Pattern('^a/b$'), 'a/b', null];
        yield 'not blank' => [new NotBlank(), " \t", 'must not be blank'];
        yield 'not blank ok' => [new NotBlank(), ' a ', null];
        yield 'one of ok' => [new OneOf(['asc', 'desc']), 'asc', null];
        yield 'one of strict' => [new OneOf([1, 2]), '1', "must be one of: 1, 2"];
        yield 'email ok' => [new Email(), 'a@example.com', null];
        yield 'email fails' => [new Email(), 'not an address', 'must be an e-mail address'];
    }

    #[DataProvider('cases')]
    public function testViolations(Constraint $constraint, mixed $value, ?string $expected): void
    {
        self::assertSame($expected, $constraint->violation($value));
    }

    public function testKeywords(): void
    {
        self::assertSame(['minLength' => 1, 'maxLength' => 5], new Length(1, 5)->keywords());
        self::assertSame(['maximum' => 9], new Range(max: 9)->keywords());
        self::assertSame(['minItems' => 1], new Count(min: 1)->keywords());
        self::assertSame(['pattern' => '^a$'], new Pattern('^a$')->keywords());
        self::assertSame(['enum' => ['a', 'b']], new OneOf(['a', 'b'])->keywords());
        self::assertSame(['format' => 'email'], new Email()->keywords());
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'no bounds' => [static fn (): Constraint => new Length()];
        yield 'inverted' => [static fn (): Constraint => new Range(5, 1)];
        yield 'negative length' => [static fn (): Constraint => new Length(-1)];
        yield 'bad pattern' => [static fn (): Constraint => new Pattern('(')];
        yield 'bad date bound' => [static fn (): Constraint => new DateRange('yesterday')];
        yield 'inverted dates' => [static fn (): Constraint => new DateRange('2001-01-01', '2000-01-01')];
        // Reflection bypasses the static non-empty-list contract, as untyped callers would.
        yield 'empty one of' => [static fn (): object => new \ReflectionClass(OneOf::class)->newInstance([])];
    }

    /**
     * @param \Closure(): object $create
     */
    #[DataProvider('invalidDefinitions')]
    public function testRejectsInvalidDefinitions(\Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);
        $create();
    }

    public function testAppliesToQueryParameters(): void
    {
        $app = new AppBuilder();
        $app->get('/search', static fn (#[Query] #[Length(min: 2)] string $q, #[Query] #[OneOf(['asc', 'desc'])] string $order = 'asc'): Text => new Text($q . ' ' . $order));
        $client = new TestClient($app->build());

        self::assertSame('ab desc', (string) $client->get('/search?q=ab&order=desc')->getBody());
        self::assertSame(400, $client->get('/search?q=a')->getStatusCode());
        self::assertStringContainsString('must be one of', (string) $client->get('/search?q=ab&order=up')->getBody());
    }
}
