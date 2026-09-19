<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Notification\Domain\Channel;
use App\Notification\Domain\MailTemplates;
use App\Notification\Domain\Notification;
use App\Notification\Infrastructure\SmtpNotifier;
use App\Notification\Service\MailWording;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The email channel, against a transport that keeps what it was given
 * (2026-09-19): the mail carries the address, the From, the subject and
 * the plain-text body, and the id the transport answered is what the
 * delivery record gets. And the words a person acts on from their inbox
 * are sentences with the link in them, not "purpose: RESET".
 */
#[CoversClass(SmtpNotifier::class)]
#[CoversClass(MailWording::class)]
final class SmtpNotifierTest extends TestCase
{
    public function testItSendsPlainTextFromTheConfiguredAddressAndReturnsTheMessageId(): void
    {
        $transport = new class () extends AbstractTransport {
            /** @var list<RawMessage> */
            public array $sent = [];

            protected function doSend(SentMessage $message): void
            {
                $this->sent[] = $message->getOriginalMessage();
            }

            public function __toString(): string
            {
                return 'capturing://';
            }
        };

        $notifier = new SmtpNotifier($transport, new Address('no-reply@raillard.org', 'Backprod'));

        self::assertSame(Channel::EMAIL, $notifier->channel());

        $id = $notifier->send('ada@acme.test', 'Set a new password', "Open this link:\nhttps://example.test/sign-in?reset=abc", []);

        self::assertNotSame('', $id);
        self::assertCount(1, $transport->sent);
        $mail = $transport->sent[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('ada@acme.test', $mail->getTo()[0]->getAddress());
        self::assertSame('no-reply@raillard.org', $mail->getFrom()[0]->getAddress());
        self::assertSame('Backprod', $mail->getFrom()[0]->getName());
        self::assertSame('Set a new password', $mail->getSubject());
        self::assertStringContainsString('https://example.test/sign-in?reset=abc', (string) $mail->getTextBody());
        // Plain text only: nothing to track, nothing to render.
        self::assertNull($mail->getHtmlBody());
    }

    public function testItRefusesAnEmptyAddressRatherThanPretendingToSend(): void
    {
        $transport = new class () extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('must not be reached');
            }

            public function __toString(): string
            {
                return 'never://';
            }
        };

        $notifier = new SmtpNotifier($transport, new Address('no-reply@raillard.org'));

        $this->expectException(RuntimeException::class);
        $notifier->send('', 'Subject', 'Body', []);
    }

    public function testTheMailsSomebodyActsOnHaveWordsOfTheirOwn(): void
    {
        $wording = new MailWording(new class () implements MailTemplates {
            public function overrides(): array
            {
                return ['account.invitation' => ['subject' => 'Bienvenue', 'body' => 'Voici votre lien : {link}']];
            }

            public function save(array $overrides): void
            {
            }
        });

        $reset = $wording->for(self::notice('account.password_reset', ['link' => 'https://example.test/sign-in?reset=abc']));
        self::assertNotNull($reset);
        [$subject, $body] = $reset;
        self::assertSame('Set a new password', $subject);
        self::assertStringContainsString('https://example.test/sign-in?reset=abc', $body);
        self::assertStringContainsString('thirty minutes', $body);
        self::assertStringNotContainsString('purpose', $body);

        // The administrator's own words win where they set them, placeholders filled.
        $invitation = $wording->for(self::notice('account.invitation', ['link' => 'https://example.test/globex/sign-in?reset=xyz']));
        self::assertNotNull($invitation);
        self::assertSame('Bienvenue', $invitation[0]);
        self::assertSame('Voici votre lien : https://example.test/globex/sign-in?reset=xyz', $invitation[1]);

        // Everything else keeps the generic form.
        self::assertNull($wording->for(self::notice('payment.failed', [])));

        // The catalogue says what is customised.
        $catalogue = $wording->catalogue();
        self::assertSame(['account.password_reset', 'account.invitation', 'account.password_changed', 'account.email_verification'], array_column($catalogue, 'type'));
        self::assertTrue($catalogue[1]['customised']);
        self::assertFalse($catalogue[0]['customised']);
        self::assertSame(['link', 'email'], $catalogue[0]['placeholders']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function notice(string $type, array $payload): Notification
    {
        return new Notification(
            'n-1',
            't-1',
            'p-1',
            'u-1',
            $type,
            'SECURITY',
            $payload,
            null,
            false,
            new DateTimeImmutable(),
            null,
        );
    }
}
