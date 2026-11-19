<?php

declare(strict_types=1);

namespace Shirakaba\Form\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Shirakaba\Core\AppBuilder;
use Shirakaba\Core\Description\FileType;
use Shirakaba\Core\Description\ObjectType;
use Shirakaba\Core\Http\Text;
use Shirakaba\Core\Testing\ResponseJson;
use Shirakaba\Core\Testing\TestClient;
use Shirakaba\Form\FileTypes;
use Shirakaba\Form\FormBody;
use Shirakaba\Form\Internal\FormPlanner;
use Shirakaba\Form\MaxFileSize;
use Shirakaba\Form\Tests\Fixtures\Avatar;
use Shirakaba\Form\Tests\Fixtures\SignUp;
use Shirakaba\Http\Message\ServerRequest;
use Shirakaba\Http\Message\Stream;
use Shirakaba\Http\Message\UploadedFile;

#[CoversClass(FormBody::class)]
#[CoversClass(FormPlanner::class)]
#[CoversClass(MaxFileSize::class)]
#[CoversClass(FileTypes::class)]
final class FormBodyTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89";

    private static function app(): AppBuilder
    {
        $app = new AppBuilder();
        $app->post(
            '/signup',
            static fn (#[FormBody] SignUp $form): Text => new Text(sprintf(
                '%s age=%s plan=%s newsletter=%s interests=%s',
                $form->email,
                var_export($form->age, true),
                $form->plan->value,
                var_export($form->newsletter, true),
                implode('|', $form->interests),
            )),
        );
        $app->post('/avatar', static fn (#[FormBody] Avatar $form): Text => new Text($form->caption . ' ' . $form->image->getClientFilename()));

        return $app;
    }

    private static function urlencoded(string $body): ResponseInterface
    {
        return new TestClient(self::app()->build())->post('/signup', $body, ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $files
     */
    private static function multipart(string $path, array $fields, array $files): ResponseInterface
    {
        return self::app()
            ->build()
            ->handle(new ServerRequest(
                'POST',
                'http://localhost' . $path,
                ['Content-Type' => 'multipart/form-data; boundary=x'],
                uploadedFiles: $files,
                parsedBody: $fields,
            ));
    }

    public function testMapsUrlEncodedForms(): void
    {
        $response = self::urlencoded('email=a%40example.com&age=20&plan=pro&newsletter=on&interests%5B%5D=php&interests[]=go');

        self::assertSame('a@example.com age=20 plan=pro newsletter=true interests=php|go', (string) $response->getBody());
    }

    public function testUsesDefaultsAndEmptyStringsAsNull(): void
    {
        self::assertSame('a@example.com age=NULL plan=free newsletter=false interests=', (string) self::urlencoded('email=a%40example.com&age=')->getBody());
    }

    /**
     * @return iterable<string, array{string, int, list<array{pointer: string, detail: string}>}>
     */
    public static function failures(): iterable
    {
        yield 'missing and invalid' => [
            'age=x&plan=gold&newsletter=maybe',
            400,
            [
                ['pointer' => '/email', 'detail' => 'is required'],
                ['pointer' => '/age', 'detail' => 'must be an integer'],
                ['pointer' => '/plan', 'detail' => 'must be one of: free, pro'],
                ['pointer' => '/newsletter', 'detail' => 'must be a boolean (true, false, 1, 0, on or off)'],
            ],
        ];
        yield 'repeated scalar and unknown field' => [
            'email=a%40example.com&email=b%40example.com&a.b=1',
            400,
            [
                ['pointer' => '/email', 'detail' => 'must appear at most once'],
                ['pointer' => '/a.b', 'detail' => 'is not an accepted field'],
            ],
        ];
        yield 'constraints' => [
            'email=nope&age=10',
            422,
            [
                ['pointer' => '/email', 'detail' => 'must be an e-mail address'],
                ['pointer' => '/age', 'detail' => 'must be at least 13'],
            ],
        ];
    }

    /**
     * @param list<array{pointer: string, detail: string}> $errors
     */
    #[DataProvider('failures')]
    public function testReportsFieldErrors(string $body, int $status, array $errors): void
    {
        $response = self::urlencoded($body);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($errors, self::withoutCodes(ResponseJson::at($response, '/errors')));
    }

    public function testRejectsOtherMediaTypesAndLargeBodies(): void
    {
        $client = new TestClient(self::app()->build());

        self::assertSame(415, $client->post('/signup', '{}', ['Content-Type' => 'application/json'])->getStatusCode());
        $app = new AppBuilder();
        $app->post('/', static fn (#[FormBody(maxBytes: 10)] SignUp $form): Text => new Text(''));
        self::assertSame(
            413,
            new TestClient($app->build())
                ->post('/', 'email=a%40example.com', ['Content-Type' => 'application/x-www-form-urlencoded'])
                ->getStatusCode(),
        );
    }

    public function testDatesAndInstants(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[FormBody] Fixtures\Booking $form): Text => new Text(
            $form->day . ' ' . ($form->remindAt === null ? '-' : \Shirakaba\Time\Rfc3339::format($form->remindAt)),
        ));
        $client = new TestClient($app->build());
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        self::assertSame('2026-11-11 -', (string) $client->post('/', 'day=2026-11-11&remindAt=', $headers)->getBody());
        self::assertSame(
            '2026-11-11 2026-11-11T00:00:00.000000Z',
            (string) $client->post('/', 'day=2026-11-11&remindAt=2026-11-11T09%3A00%3A00%2B09%3A00', $headers)->getBody(),
        );
        $invalid = $client->post('/', 'day=2026-11-31&remindAt=2026-11-11T09%3A00', $headers);
        self::assertSame(400, $invalid->getStatusCode());
        self::assertSame(
            [
                ['pointer' => '/day', 'detail' => 'must be a date (YYYY-MM-DD)'],
                ['pointer' => '/remindAt', 'detail' => 'must be an RFC 3339 date-time with an offset'],
            ],
            self::withoutCodes(ResponseJson::at($invalid, '/errors')),
        );
    }

    public function testLimitsTheNumberOfFields(): void
    {
        $app = new AppBuilder();
        $app->post('/', static fn (#[FormBody(allowUnknownFields: true, maxFields: 3)] SignUp $form): Text => new Text($form->email));
        $client = new TestClient($app->build());
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        self::assertSame(200, $client->post('/', 'email=a%40example.com&age=30&plan=free', $headers)->getStatusCode());
        self::assertSame(413, $client->post('/', 'email=a%40example.com&age=30&plan=free&x=1', $headers)->getStatusCode());
    }

    public function testAcceptsUploadedFilesCheckedByContent(): void
    {
        $png = new UploadedFile(Stream::fromString(self::PNG), strlen(self::PNG), UPLOAD_ERR_OK, 'me.png', 'image/png');

        self::assertSame('Me me.png', (string) self::multipart('/avatar', ['caption' => 'Me'], ['image' => $png])->getBody());
    }

    public function testRejectsFilesByDetectedTypeNotClaimedType(): void
    {
        $script = new UploadedFile(Stream::fromString('<?php echo 1;'), 13, UPLOAD_ERR_OK, 'me.png', 'image/png');
        $response = self::multipart('/avatar', ['caption' => 'Me'], ['image' => $script]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [['pointer' => '/image', 'detail' => 'must be one of the file types: image/png']],
            self::withoutCodes(ResponseJson::at($response, '/errors')),
        );
    }

    public function testUploadErrors(): void
    {
        $tooLarge = new UploadedFile(Stream::fromString(''), null, UPLOAD_ERR_INI_SIZE);
        $missing = new UploadedFile(Stream::fromString(''), null, UPLOAD_ERR_NO_FILE);
        $big = new UploadedFile(Stream::fromString(str_repeat("\x89PNG", 400)), 1600, UPLOAD_ERR_OK, 'big.png');

        self::assertSame(413, self::multipart('/avatar', ['caption' => 'x'], ['image' => $tooLarge])->getStatusCode());
        self::assertSame(
            [['pointer' => '/image', 'detail' => 'is required']],
            self::withoutCodes(ResponseJson::at(self::multipart('/avatar', ['caption' => 'x'], ['image' => $missing]), '/errors')),
        );
        self::assertSame('must be at most 1024 bytes', ResponseJson::at(self::multipart('/avatar', ['caption' => 'x'], ['image' => $big]), '/errors/0/detail'));
    }

    public function testNestedMultipartStructuresAreRejected(): void
    {
        $png = new UploadedFile(Stream::fromString(self::PNG), strlen(self::PNG), UPLOAD_ERR_OK, 'me.png');
        $response = self::multipart('/avatar', ['caption' => ['a' => ['b' => 'c']]], ['image' => $png]);

        self::assertSame([['pointer' => '/caption', 'detail' => 'has an unsupported structure']], self::withoutCodes(ResponseJson::at($response, '/errors')));
    }

    public function testDescribesMultipartBodiesWithFiles(): void
    {
        $routes = self::app()->describe()->routes;
        $signUp = ($routes[0] ?? null)?->parameters[0] ?? null;
        $avatar = ($routes[1] ?? null)?->parameters[0] ?? null;
        self::assertNotNull($signUp);
        self::assertNotNull($avatar);

        self::assertSame(['application/x-www-form-urlencoded', 'multipart/form-data'], $signUp->mediaTypes);
        self::assertSame(['multipart/form-data'], $avatar->mediaTypes);
        $type = $avatar->type;
        self::assertInstanceOf(ObjectType::class, $type);
        self::assertEquals(new FileType(['image/png']), ($type->properties[1] ?? null)?->type);
    }

    /**
     * The field errors without their codes, which the code tests check.
     */
    private static function withoutCodes(mixed $errors): mixed
    {
        return is_array($errors)
            ? array_map(static fn (mixed $error): mixed => is_array($error) ? array_diff_key($error, ['code' => true]) : $error, $errors)
            : $errors;
    }
}
