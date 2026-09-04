<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\Notifier;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An outbound channel that is honestly not one.
 *
 * It stands in for email, SMS and WhatsApp until a real provider is wired,
 * and it exists so the whole path — gate, dispatch, delivery record, retry —
 * is exercised end to end without sending anything to anybody.
 *
 * It is deliberately *one* class configured with a channel name rather than
 * three near-identical stubs: what differs between a real SMTP adapter and a
 * real SMS adapter is everything, and what differs between three fakes is
 * nothing.
 *
 * It refuses an empty address rather than pretending to send. A stub that
 * always succeeds would hide exactly the failure the delivery record exists
 * to capture.
 */
final class LogNotifier implements Notifier
{
    public function __construct(
        private readonly string $channel,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $address, string $subject, string $body, array $payload): string
    {
        if ($address === '') {
            throw new RuntimeException('No address for this channel.');
        }

        // The subject and the recipient, never the body: a notification body
        // can carry a name, an amount or a link, and a log is the wrong place
        // for any of them (§31, §26.1).
        $this->logger->info('Notification dispatched', [
            'channel' => $this->channel,
            'subject' => $subject,
            'recipient' => self::masked($address),
        ]);

        return sprintf('%s-%s', strtolower($this->channel), bin2hex(random_bytes(8)));
    }

    /**
     * Enough of an address to tell two recipients apart in a log, and not
     * enough to be a copy of one.
     */
    private static function masked(string $address): string
    {
        $length = strlen($address);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($address, 0, 2) . str_repeat('*', $length - 4) . substr($address, -2);
    }
}
