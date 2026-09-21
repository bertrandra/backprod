<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

use DateTimeImmutable;

/**
 * One event on its way to one product, and what became of it so far.
 *
 * Delivered, parked or still due is three facts, not one status column:
 * `deliveredAt` says it arrived, `parkedAt` says the platform gave up (or
 * the product answered 400, which is not retried), and neither means the
 * queue will try again at `nextAttemptAt`. The last answer is kept so an
 * operator reading the console sees *why* — the status, and the class of
 * error, never a response body (§31).
 */
final class WebhookDelivery
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        /** The code, which is what the payload names the product by. */
        public readonly string $productCode,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?string $tenantId,
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
        public readonly int $attempt,
        public readonly DateTimeImmutable $nextAttemptAt,
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly ?DateTimeImmutable $parkedAt,
        public readonly ?int $lastStatus,
        public readonly ?string $lastError,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
