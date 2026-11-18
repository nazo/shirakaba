# Mail and templates

Applications send mail: address confirmations, password resets, notifications. A mail needs a
subject, a plain-text body and usually an HTML body, often in the recipient's language, and must
leave only once the data it describes is committed. Mail has its own risks: header injection through
a subject or display name, HTML bodies built from user input, credentials sent without TLS, and slow
SMTP servers holding up a request. Non-ASCII subjects and names need RFC 2047 encoding, which
hand-written headers get wrong. The dependency policy rules out the usual mail and template
libraries, so Shirakaba has its own, kept small.

## Templates

`shirakaba/template` is a separate package, so plain-text exports or pages can use it too.

- Templates are files under one directory, loaded by name, never by a path from user input; `..` and
  absolute names are refused.
- The data is a readonly object, not an array. Templates read its public properties and those of
  nested objects (`{{ user.name }}`): no method calls, no function calls, no arithmetic. A template
  cannot run code, so it cannot leak data or call services; the price is that every formatted value is
  prepared in PHP.
- The syntax is small and fixed:
  - `{{ path }}` prints a value;
  - `{% if path %}`, `{% elseif path %}`, `{% else %}`, `{% endif %}`, with `not`; conditions test
    booleans, empty strings, empty lists and null, nothing else;
  - `{% for item in path %}` ... `{% else %}` ... `{% endfor %}` over lists;
  - `{% layout "name" %}` wraps the template in a layout that prints `{{ content }}`;
  - `{# comment #}`.

  A block tag or comment alone on its line removes the line, so text templates do not fill with blank
  lines. Filters, macros, includes and expressions are left out until an application needs them.
- Printable values are `string`, `int`, `Stringable` and `Html`. Anything else is an error, so
  formatting (dates, amounts, plurals) happens in typed PHP code.
- Escaping follows the file type: `.html` templates escape with `htmlspecialchars` (`ENT_QUOTES |
  ENT_SUBSTITUTE`, UTF-8); `.txt` templates print as is. An `Html` value (markup the application
  vouches for) is printed unescaped in HTML and refused in text. There is no `raw` filter.
- Templates are parsed into an immutable tree, kept per name in the application-scoped engine. Nothing
  is compiled to PHP or evaluated, so no writable directory is needed. Errors name the template, line
  and column.
- `TemplateChecker` (a test helper and the `templates:check` command) parses every template and, for
  templates declared with their data class, checks every path against that class's property types,
  including the item types of `list<T>`. Typos fail in CI instead of in a sent mail.
- Locales are a parameter, not global state: `render('welcome.txt', $data, 'ja-JP')` looks for
  `welcome.ja-JP.txt`, then `welcome.ja.txt`, then `welcome.txt`.

A template engine for HTML pages would start from this syntax and add attribute, URL and script
contexts.

## Mail

`shirakaba/mailer` needs ext-openssl for TLS and no third-party library.

- `Email` is an immutable value: `from`, `to`, `cc`, `bcc`, `replyTo`, `subject`, `text`, an optional
  `html`, attachments and extra headers. `Address` validates the address and refuses CR, LF and NUL in
  the address and the display name. Header names and values are checked the same way, so no header can
  be injected.
- `Mailer` has one method, `send(Email): SentEmail` (the Message-ID and the size).
- MIME is written by the package: `multipart/alternative` for text and HTML, `multipart/mixed` for
  attachments, `multipart/related` for inline images; quoted-printable bodies, RFC 2047 encoded words
  for non-ASCII headers, CRLF line endings and lines folded under 998 octets. `Date` comes from the
  PSR-20 clock and Message-ID from `random_bytes` and the sender's domain. Addresses with a non-ASCII
  local part are refused (SMTPUTF8 is not supported); non-ASCII domains are converted with IDNA when
  ext-intl is present.
- `SmtpTransport` speaks SMTP over `stream_socket_client`: implicit TLS (465) or STARTTLS (587),
  STARTTLS required by default, certificates verified, AUTH PLAIN and LOGIN only after TLS, timeouts
  on connecting and on every reply, a bound on reply length, and dot-stuffing.
  `SmtpTransport::fromDsn()` reads one `smtp://` or `smtps://` string for convenience.
- `TemplatedEmail` joins mail and templates: a mail template is `name.subject.txt`, `name.txt` and an
  optional `name.html`. The subject is rendered, trimmed and refused if it contains a line break.
- Every send can be reported as an `EmailSent` event (transport, recipient count, size, duration, error
  kind) without addresses, subject or content; `ObservedMailer($mailer, $scope)` does the reporting
  where a request or job scope exists.
- Testing: `RecordingMailer` keeps sent mail for assertions (the rendered text and HTML, links in the
  body); `NullMailer` and `LogMailer` (to a PSR-3 logger) do not send. The SMTP client is tested
  against a scripted connection, and against a local SMTP test server when `SHIRAKABA_TEST_SMTP_DSN` is
  set.

Providers' HTTP APIs, DKIM signing, bounce handling, Markdown bodies, HTML-to-text conversion, CSS
inlining and receiving mail are not part of the package.

## Sending outside the request

An SMTP exchange takes long and fails in ways a request should not wait for.

- `QueuedMailer` (`shirakaba/mailer-queue`, so the mailer itself needs no queue or database) is a
  `Mailer` built per request from the request's Writer. It pushes a `SendEmail` job in the caller's
  transaction, so the mail leaves only when the data is committed, with the queue's retries
  ([background work](10-background-work.md)). A permanent failure (SMTP 5xx) fails the job without
  retrying it. A retried job reuses its Message-ID so receivers can drop duplicates.
- Attachments travel in the job up to a size limit. For mail built from database rows, an application
  job (`SendWelcomeMail { userId }`) that renders when it runs is better, so the mail shows the data at
  sending time.
- A queued mail waits in the job's payload, and a failed job keeps it. Mail carrying a secret (a
  one-time code) is sent with `Writer::afterCommit()` instead, which is also faster for the waiting
  user.
