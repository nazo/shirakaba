# Sending mail

```sh
composer require shirakaba/mailer shirakaba/mailer-queue
```

`shirakaba/mailer` builds and sends mail; `shirakaba/template` (installed with it) renders
subjects and bodies; `shirakaba/mailer-queue` sends through the queue after the transaction
commits ([mail and templates](../design/12-mail-and-templates.md)).

## Configuration

```php
$transport = SmtpTransport::fromDsn((string) getenv('MAILER_DSN'));
// smtp://user:password@smtp.example.com:587   STARTTLS (required), credentials only after TLS
// smtps://user:password@smtp.example.com:465  implicit TLS
// smtp://localhost:1025?tls=none              a local relay such as Mailpit, without credentials
```

Certificates are verified, every reply has a timeout and a size bound, and a server that
does not offer STARTTLS is refused rather than spoken to in plain text. In tests use
`RecordingMailer`; in development `LogMailer` writes each message to a PSR-3 logger, and
Mailpit shows them in a browser.

## Templates

A mail is three files in a template directory: `<name>.subject.txt`, `<name>.txt` and,
optionally, `<name>.html`. Each is looked up in the recipient's locale first
(`welcome.ja.txt`, then `welcome.txt`):

```text
{# templates/mail/welcome.txt #}
{% layout "mail/layout" %}
Hello {{ user.name }},

{% if user.invited %}
{{ inviter.name }} invited you.
{% endif %}
{% for item in items %}
{{ loop.index }}. {{ item.title }}
{% else %}
Nothing yet.
{% endfor %}
Confirm your address: {{ link }}
```

The data is a readonly object; templates read its public properties and those of nested
objects. There are no method calls, filters or arithmetic: format dates, amounts and plurals
in PHP and pass strings. Printable values are `string`, `int` and `Stringable`; `.html`
templates escape them, and print `Html` (markup the application vouches for) as is. Text
templates refuse `Html`. Conditions test booleans, strings, lists and null. A block tag alone
on its line takes the line with it, so text mail has no stray blank lines. A template that
starts with `{% layout "name" %}` is rendered into `name.txt` or `name.html` as `{{ content }}`.

```php
$templates = new Templates(new TemplateDirectory(__DIR__ . '/templates'));        // application-scoped
$compose = new TemplatedEmail($templates, new Address('noreply@example.com', 'Example'));

$email = $compose->email('mail/welcome', new Welcome($user, $items, $link), [new Address($user->email, $user->name)], $user->locale);
```

Check templates in CI, so a typo fails there instead of in a sent mail:

```php
TemplateCommands::register($cli, static fn (): TemplateDirectory => $directory, ['mail/welcome' => Welcome::class]);
// app templates:check
```

It parses every template and checks declared ones against their data class: unknown
properties, values that cannot be printed, conditions on objects, and loops over values that
are not lists (item types come from `list<T>` in PHPDoc).

## Sending through the queue

Sending from a request makes the client wait for an SMTP exchange and loses the mail when
the server is down. Queue it instead, in the transaction that changes the data, so it leaves
only once the data is committed and is retried on temporary failures:

```php
MailJobs::register($jobs, $transport);                       // at boot, with the other jobs

final readonly class Signups
{
    public function __construct(private Writer $db, private Jobs $jobs, private TemplatedEmail $mail) {}

    public function signUp(NewUser $input): void
    {
        $this->db->transaction(function (Writer $db) use ($input): void {
            $user = Entities::insert(new User(...), $db);
            new QueuedMailer($this->jobs, $db)->send($this->mail->email('mail/welcome', ..., [new Address($user->email)]));
        });
    }
}
```

The job keeps the Message-ID it was queued with, so a retry is recognisable as the same mail.
SMTP 4xx answers and lost connections are retried; 5xx answers fail the job at once. Each
attempt is reported to observers as an `EmailSent` event without addresses or content.

For mail built from database rows, prefer an application job that carries ids
(`SendWelcomeMail { userId }`) and builds the mail when it runs, so the mail shows the data
at sending time.

### Secrets in mail

A queued job holds the whole mail until it is sent, and a job that fails for good keeps its
payload in the failed list. A mail carrying a secret, such as a one-time sign-in code, should
not wait in the queue: send it right after the commit, which is also faster for a user who is
waiting for it.

```php
$db->transaction(function (Writer $db) use ($code, $email): void {
    ... store the code's hash ...
    $db->afterCommit(fn () => new ObservedMailer($this->transport, $scope)->send($email));
});
```

## Testing

```php
$mailer = new RecordingMailer();
$client->post('/signup', ...);

$mail = $mailer->sentTo('ann@example.com');
$link = RecordingMailer::links($mail->text)[0];
$client->get($link);
```

With the queue, run the jobs in the test (`$runner->runPending()`) before looking.
