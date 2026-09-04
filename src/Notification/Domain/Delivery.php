<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use DateTimeImmutable;

/**
 * One attempt on one channel, with its own outcome (§27.1).
 *
 * A notification carries the intent; this carries what happened. One failing
 * channel must not lose the notification, and one succeeding channel must not
 * make the others look sent.
 *
 * `SUPPRESSED` is a result, not a silence. Writing nothing when a delivery is
 * not attempted makes "did we tell them?" unanswerable — which is exactly the
 * question a pre-renewal notice has to settle.
 */
final class Delivery
{
    public const PENDING = 'PENDING';
    public const SENT = 'SENT';
    public const DELIVERED = 'DELIVERED';
    public const FAILED = 'FAILED';
    public const SUPPRESSED = 'SUPPRESSED';

    public const NO_CONSENT = 'NO_CONSENT';
    public const OPTED_OUT = 'OPTED_OUT';
    public const NO_ADDRESS = 'NO_ADDRESS';
    public const CHANNEL_UNAVAILABLE = 'CHANNEL_UNAVAILABLE';

    public function __construct(
        public readonly string $id,
        public readonly string $notificationId,
        public readonly string $channel,
        public readonly string $status,
        public readonly ?string $suppressionReason,
        public readonly ?string $providerMessageId,
        public readonly int $attempts,
        public readonly ?string $failureReason,
        public readonly ?string $renderedBody,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?DateTimeImmutable $deliveredAt,
    ) {
    }

    public function wasAttempted(): bool
    {
        return $this->status !== self::PENDING && $this->status !== self::SUPPRESSED;
    }

    public function reached(): bool
    {
        return $this->status === self::SENT || $this->status === self::DELIVERED;
    }
}
