<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Testing;

use LogicException;
use Shirakaba\Mailer\Email;
use Shirakaba\Mailer\Mailer;
use Shirakaba\Mailer\SentEmail;

/**
 * Keeps sent mail for assertions instead of sending it:
 *
 *     $mailer = new RecordingMailer();
 *     $client->post('/signup', ...);
 *     $mail = $mailer->sentTo('ann@example.com');
 *     $link = RecordingMailer::links($mail->text)[0];
 */
final class RecordingMailer implements Mailer
{
    /** @var list<Email> */
    private array $sent = [];
    private int $next = 0;

    public function send(Email $email): SentEmail
    {
        $this->sent[] = $email;

        return new SentEmail($email->messageId ?? sprintf('recorded-%d@test', ++$this->next));
    }

    /**
     * @return list<Email>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * The last mail sent to $address (To, Cc or Bcc).
     *
     * @throws LogicException when none was
     */
    public function sentTo(string $address): Email
    {
        foreach (array_reverse($this->sent) as $email) {
            foreach ($email->recipients() as $recipient) {
                if (strcasecmp($recipient->address, $address) === 0) {
                    return $email;
                }
            }
        }

        throw new LogicException(sprintf('No mail was sent to %s; %d mail(s) were sent.', $address, count($this->sent)));
    }

    /**
     * The http and https links in a body, in order.
     *
     * @return list<string>
     */
    public static function links(string $body): array
    {
        preg_match_all('#https?://[^\s<>"\']+#', $body, $matches);

        return array_values(array_map(static fn (string $link): string => html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches[0]));
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
