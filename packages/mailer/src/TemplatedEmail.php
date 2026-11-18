<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use InvalidArgumentException;
use Shirakaba\Template\TemplateError;
use Shirakaba\Template\Templates;

/**
 * Builds an Email from three templates (design/12-mail-and-templates.md): `<name>.subject.txt`, `<name>.txt` and an
 * optional `<name>.html`, each looked up in the recipient's locale first.
 *
 *     $compose = new TemplatedEmail($templates, new Address('noreply@example.com', 'Example'));
 *     $email = $compose->email('mail/welcome', new Welcome($user->name, $link), [new Address($user->email)], 'ja');
 *
 * The subject is rendered, trimmed, and refused when it still contains a line break.
 */
final readonly class TemplatedEmail
{
    public function __construct(
        private Templates $templates,
        private Address $from,
    ) {}

    /**
     * @param list<Address> $to
     * @param array<string, string> $headers
     * @throws TemplateError when a template is missing or does not fit the data
     * @throws InvalidArgumentException when the subject has a line break
     */
    public function email(string $name, object $data, array $to, ?string $locale = null, array $headers = []): Email
    {
        $subject = trim($this->templates->render($name . '.subject.txt', $data, $locale));
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new InvalidArgumentException(sprintf('The subject of %s renders to more than one line.', $name));
        }
        $html = $this->templates->exists($name . '.html', $locale) ? $this->templates->render($name . '.html', $data, $locale) : null;

        return new Email($this->from, $to, $subject, $this->templates->render($name . '.txt', $data, $locale), $html, headers: $headers);
    }
}
