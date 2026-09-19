<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\Channel;
use App\Notification\Domain\Notifier;
use RuntimeException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The email channel, for real (2026-09-19).
 *
 * Behind the same `Notifier` port as the stand-in it replaces, so nothing
 * above it — the gate, the dispatch, the delivery record, the retry — knows
 * whether a mail left the building or the log. Symfony Mailer carries it:
 * one transport built from `MAIL_DSN`, which is where the SMTP host, port
 * and credentials live and the only place they do (§31).
 *
 * Plain text, on purpose. What this platform sends is a link and a sentence
 * — a reset, an invitation, a failed payment — and a mail that is only text
 * renders everywhere, lands better with filters and carries no tracking.
 *
 * It returns the provider's message id, which is what the delivery record
 * keeps and what a support ticket quotes to the mail host.
 */
final class SmtpNotifier implements Notifier
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Address $from,
    ) {
    }

    public function channel(): string
    {
        return Channel::EMAIL;
    }

    public function isLive(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $address, string $subject, string $body, array $payload): string
    {
        if ($address === '') {
            throw new RuntimeException('No address for this channel.');
        }

        $email = (new Email())
            ->from($this->from)
            ->to(new Address($address))
            ->subject($subject)
            ->text($body);

        $sent = $this->transport->send($email);

        if ($sent === null) {
            // A transport that answers nothing has sent nothing: said so,
            // and recorded as a failure by the dispatcher, never as a
            // delivery with no reference.
            throw new RuntimeException('The mail transport answered nothing.');
        }

        return $sent->getMessageId();
    }
}
