<?php

declare(strict_types=1);

namespace Shirakaba\Mailer;

use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Shirakaba\Time\SystemClock;

/**
 * Writes an Email as an RFC 5322 message (design/12-mail-and-templates.md): multipart/alternative for text and HTML,
 * multipart/related for inline parts, multipart/mixed for attachments; quoted-printable bodies,
 * base64 attachments, RFC 2047 encoded words for non-ASCII headers, RFC 2231 file names, CRLF
 * line endings and headers folded below 78 characters where possible. Date comes from the
 * clock, Message-ID from random bytes and the sender's domain.
 */
final readonly class MimeWriter
{
    public function __construct(
        private ClockInterface $clock = new SystemClock(),
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * A Message-ID for $email: its own, or a new one in the sender's domain.
     */
    public function messageId(Email $email): string
    {
        return $email->messageId ?? bin2hex($this->random->getBytes(16)) . '@' . $email->from->domain();
    }

    /**
     * The whole message with CRLF line endings, without the Bcc header.
     */
    public function write(Email $email, ?string $messageId = null): string
    {
        $headers = [
            'Date' => $this->clock->now()->format(\DateTimeInterface::RFC2822),
            'Message-ID' => '<' . ($messageId ?? $this->messageId($email)) . '>',
            'From' => self::addresses([$email->from]),
        ];
        if ($email->to !== []) {
            $headers['To'] = self::addresses($email->to);
        }
        if ($email->cc !== []) {
            $headers['Cc'] = self::addresses($email->cc);
        }
        if ($email->replyTo !== []) {
            $headers['Reply-To'] = self::addresses($email->replyTo);
        }
        $headers['Subject'] = self::encodeText($email->subject);
        foreach ($email->headers as $name => $value) {
            $headers[$name] = self::encodeText($value);
        }
        $headers['MIME-Version'] = '1.0';

        [$partHeaders, $body] = $this->body($email);
        $out = '';
        foreach ([...$headers, ...$partHeaders] as $name => $value) {
            $out .= self::fold($name . ': ' . $value) . "\r\n";
        }

        return $out . "\r\n" . $body;
    }

    /**
     * @return array{array<string, string>, string} the top part's headers and its body
     */
    private function body(Email $email): array
    {
        $text = self::textPart($email->text, 'text/plain');
        $content = $email->html === null ? $text : $this->multipart('alternative', [$text, self::textPart($email->html, 'text/html')]);
        $inline = array_values(array_filter($email->attachments, static fn (Attachment $attachment): bool => $attachment->contentId !== null));
        $attached = array_values(array_filter($email->attachments, static fn (Attachment $attachment): bool => $attachment->contentId === null));
        if ($inline !== []) {
            $content = $this->multipart('related', [$content, ...array_map(self::attachmentPart(...), $inline)]);
        }
        if ($attached !== []) {
            $content = $this->multipart('mixed', [$content, ...array_map(self::attachmentPart(...), $attached)]);
        }

        return $content;
    }

    /**
     * @return array{array<string, string>, string}
     */
    private static function textPart(string $text, string $type): array
    {
        $normalized = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text);

        return [
            ['Content-Type' => $type . '; charset=utf-8', 'Content-Transfer-Encoding' => 'quoted-printable'],
            self::quotedPrintable($normalized),
        ];
    }

    /**
     * @return array{array<string, string>, string}
     */
    private static function attachmentPart(Attachment $attachment): array
    {
        $headers = [
            'Content-Type' => $attachment->contentType . '; ' . self::parameter('name', $attachment->filename),
            'Content-Transfer-Encoding' => 'base64',
            'Content-Disposition' => ($attachment->contentId === null ? 'attachment' : 'inline') . '; ' . self::parameter('filename', $attachment->filename),
        ];
        if ($attachment->contentId !== null) {
            $headers['Content-ID'] = '<' . $attachment->contentId . '>';
        }

        return [$headers, rtrim(chunk_split(base64_encode($attachment->content), 76, "\r\n"), "\r\n")];
    }

    /**
     * @param list<array{array<string, string>, string}> $parts
     * @return array{array<string, string>, string}
     */
    private function multipart(string $subtype, array $parts): array
    {
        $boundary = '=_' . bin2hex($this->random->getBytes(12));
        $body = '';
        foreach ($parts as [$headers, $content]) {
            $body .= '--' . $boundary . "\r\n";
            foreach ($headers as $name => $value) {
                $body .= self::fold($name . ': ' . $value) . "\r\n";
            }
            $body .= "\r\n" . $content . "\r\n";
        }

        return [['Content-Type' => 'multipart/' . $subtype . '; boundary="' . $boundary . '"'], $body . '--' . $boundary . '--'];
    }

    /**
     * Quoted-printable with CRLF hard breaks and "=" soft breaks within 76 characters, splitting
     * only between encoded characters.
     */
    private static function quotedPrintable(string $text): string
    {
        $lines = [];
        foreach (explode("\r\n", $text) as $line) {
            $encoded = '';
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $byte = ord($char);
                $last = $i === ($length - 1);
                if ($byte >= 33 && $byte <= 126 && $char !== '=' || ($char === ' ' || $char === "\t") && !$last) {
                    $encoded .= $char;
                } else {
                    $encoded .= sprintf('=%02X', $byte);
                }
            }
            // A line starting with "." would be stuffed by SMTP; "From " is mangled by some agents.
            if (str_starts_with($encoded, '.')) {
                $encoded = '=2E' . substr($encoded, 1);
            } elseif (str_starts_with($encoded, 'From ')) {
                $encoded = '=46' . substr($encoded, 1);
            }
            while (strlen($encoded) > 76) {
                $cut = 75;
                // Do not split an "=XX" escape.
                if ($encoded[$cut - 1] === '=') {
                    $cut -= 1;
                } elseif ($encoded[$cut - 2] === '=') {
                    $cut -= 2;
                }
                $lines[] = substr($encoded, 0, $cut) . '=';
                $encoded = substr($encoded, $cut);
            }
            $lines[] = $encoded;
        }

        return implode("\r\n", $lines);
    }

    /**
     * @param list<Address> $addresses
     */
    private static function addresses(array $addresses): string
    {
        return implode(', ', array_map(static function (Address $address): string {
            if ($address->name === '') {
                return $address->address;
            }

            return self::displayName($address->name) . ' <' . $address->address . '>';
        }, $addresses));
    }

    private static function displayName(string $name): string
    {
        if (preg_match('/[^\x20-\x7e]/', $name) === 1) {
            return self::encodedWords($name);
        }
        if (preg_match('/\A[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+\z/', $name) === 1) {
            return $name;
        }

        return '"' . addcslashes($name, '"\\') . '"';
    }

    /**
     * Header text: as is when it is printable ASCII, otherwise RFC 2047 encoded words.
     */
    private static function encodeText(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) === 1 || str_contains($text, '=?') ? self::encodedWords($text) : $text;
    }

    /**
     * Base64 encoded words of at most 75 characters, split between UTF-8 characters.
     */
    private static function encodedWords(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            // Invalid UTF-8 becomes U+FFFD instead of breaking the header.
            $text = htmlspecialchars_decode(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
        }
        $words = [];
        $chunk = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars === false ? [] : $chars as $char) {
            // 45 bytes encode to 60 characters, which with "=?utf-8?B?" and "?=" stays under 75.
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?utf-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?utf-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode(' ', $words);
    }

    /**
     * A MIME parameter: quoted when ASCII, RFC 2231 encoded otherwise.
     */
    private static function parameter(string $name, string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            return $name . "*=utf-8''" . rawurlencode($value);
        }

        return $name . '="' . $value . '"';
    }

    /**
     * Folds a header line at spaces so lines stay under 78 characters where they can.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 78) {
            return $line;
        }
        $folded = '';
        $current = '';
        foreach (explode(' ', $line) as $index => $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($index > 0 && strlen($candidate) > 78 && $current !== '') {
                $folded .= $current . "\r\n";
                $current = ' ' . $word;
            } else {
                $current = $candidate;
            }
        }

        return $folded . $current;
    }
}
