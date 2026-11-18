<?php

declare(strict_types=1);

namespace Shirakaba\Mailer\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shirakaba\Mailer\Address;
use Shirakaba\Mailer\TemplatedEmail;
use Shirakaba\Mailer\Testing\RecordingMailer;
use Shirakaba\Mailer\Tests\Fixtures\Welcome;
use Shirakaba\Template\TemplateDirectory;
use Shirakaba\Template\Templates;

#[CoversClass(TemplatedEmail::class)]
final class TemplatedEmailTest extends TestCase
{
    private static function compose(): TemplatedEmail
    {
        return new TemplatedEmail(new Templates(new TemplateDirectory(__DIR__ . '/Fixtures/templates')), new Address('noreply@example.com', 'Example'));
    }

    public function testRendersSubjectTextAndHtmlInTheLocale(): void
    {
        $data = new Welcome('Ann & Bob', 'https://example.com/confirm?a=1&b=2');
        $email = self::compose()->email('mail/welcome', $data, [new Address('ann@example.com')], 'ja');

        self::assertSame('Ann & Bob さん、ようこそ', $email->subject);
        self::assertSame("Hello Ann & Bob,\nConfirm: https://example.com/confirm?a=1&b=2\n", $email->text);
        self::assertSame("<p>Hello Ann &amp; Bob, <a href=\"https://example.com/confirm?a=1&amp;b=2\">confirm</a></p>\n", $email->html);
        self::assertSame('Welcome, Ann & Bob', self::compose()->email('mail/welcome', $data, [new Address('ann@example.com')])->subject);

        $mailer = new RecordingMailer();
        $mailer->send($email);
        self::assertSame(['https://example.com/confirm?a=1&b=2'], RecordingMailer::links((string) $mailer->sentTo('ann@example.com')->html));
    }

    public function testRefusesASubjectOfSeveralLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::compose()->email('mail/broken', new Welcome('x', 'y'), [new Address('ann@example.com')]);
    }
}
