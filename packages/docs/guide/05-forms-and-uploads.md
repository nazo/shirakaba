# Forms and uploads

```sh
composer require shirakaba/form
```

```php
final readonly class SignUp
{
    public function __construct(
        #[Email] public string $email,
        public bool $newsletter = false,              // a checkbox: "on" or absent
        #[FormList('string')] public array $interests = [], // interests[]=...
        #[MaxFileSize(2 * 1024 * 1024)] #[FileTypes(['image/png', 'image/jpeg'])]
        public ?UploadedFileInterface $avatar = null,
    ) {}
}

public function signUp(#[FormBody] SignUp $form): Json
```

- Both `application/x-www-form-urlencoded` and `multipart/form-data` are accepted.
  URL-encoded bodies are parsed from the raw body, so keys are not renamed as in `$_POST`.
- An empty string is null for nullable non-string fields.
- `FileTypes` checks the type detected from the content; the type the client claims is
  never trusted.
- Files over PHP's upload limit are a 413; other upload errors are field errors.
- URL-encoded bodies over `maxBytes` (1 MiB) or `maxFields` (1,000) are a 413.
- Move accepted files with `$form->avatar->moveTo($path)`. Under FrankenPHP, RoadRunner and
  Swoole, uploads the application did not move are deleted after the request.

Errors use the same format as JSON bodies.
