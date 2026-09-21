<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * The outbox (ADR-051 §5): what is waiting to be sent, what was, and what
 * the platform gave up on.
 */
interface WebhookDeliveries
{
    /**
     * Turns the platform's subscription history into deliveries: every
     * `subscription_events` row past the collector's cursor becomes one
     * outbox row for the product the subscription belongs to — when that
     * product has an address — and the cursor moves.
     *
     * Reading the history rather than hooking every place that writes it is
     * deliberate: the history is append-only and already complete (§13,
     * non-negotiable #18), so nothing can happen to a subscription that this
     * misses, and the commerce module does not learn that products exist
     * beside the platform.
     *
     * The cursor trails the clock by a margin, because a row's timestamp is
     * its transaction's start and a row committing late would otherwise land
     * behind a cursor that had already passed it.
     *
     * @return int how many deliveries were written
     */
    public function collectSubscriptionEvents(): int;

    /**
     * Claims what is due: undelivered, not parked, whose next attempt is now
     * or earlier. The claim moves `next_attempt_at` forward in the statement
     * that selects, so two runners racing take different rows.
     *
     * @return list<WebhookDelivery>
     */
    public function claimDue(int $limit, int $leaseSeconds): array;

    public function recordDelivered(string $deliveryId, int $status): void;

    /**
     * A failed attempt: retried at `$nextAttemptAt`, or parked when null.
     */
    public function recordFailure(string $deliveryId, ?int $status, string $error, ?\DateTimeImmutable $nextAttemptAt): void;

    /**
     * The newest deliveries of one product, delivered or not.
     *
     * @return list<WebhookDelivery>
     */
    public function recent(string $productId, int $limit): array;

    /**
     * Puts a parked delivery back on the queue, due now. Null when there is
     * no such delivery on this product; a delivery that is not parked is
     * returned unchanged.
     */
    public function retry(string $productId, string $deliveryId): ?WebhookDelivery;
}
