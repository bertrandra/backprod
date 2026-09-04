<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use DateTimeImmutable;

/**
 * The intent to inform somebody (§27.1).
 *
 * Deliberately not a message (§12.3). A conversation has participants, an
 * order and a reply; this is one-way. Keeping them apart is why a support
 * thread does not fill with system noise, and why a human message cannot be
 * muted by a preference.
 *
 * The payload is *data*, not a rendered sentence: the language is the
 * recipient's and the format is the channel's, and both are decided at send
 * time. The exception is a notification with legal effect — a pre-renewal
 * notice (§13.1), a formal demand — where what was actually sent has to stay
 * re-readable, for the reason an invoice keeps its snapshot.
 */
final class Notification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $recipientUserId,
        public readonly string $type,
        public readonly string $category,
        public readonly array $payload,
        public readonly ?string $dedupKey,
        public readonly bool $legalEffect,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $readAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
