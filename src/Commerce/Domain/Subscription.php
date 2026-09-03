<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * What a tenant subscribed to (§12).
 *
 * It refers to an offer *version*, so the terms it records cannot change
 * under the tenant when the offer is repriced.
 *
 * The period and the status are separate facts and both matter. §12 keeps
 * the subscription period deliberately distinct from the offer's commercial
 * window: one is when this tenant is entitled to the product, the other is
 * when the offer could be sold at all.
 */
final class Subscription
{
    public const ACTIVE = 'ACTIVE';
    public const CANCELLED = 'CANCELLED';
    public const EXPIRED = 'EXPIRED';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly SubscribedOffer $offer,
        public readonly string $status,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $currentPeriodStart,
        public readonly ?DateTimeImmutable $currentPeriodEnd,
        public readonly bool $cancelAtPeriodEnd,
        public readonly ?DateTimeImmutable $cancelledAt,
        public readonly ?DateTimeImmutable $endedAt,
    ) {
    }

    /**
     * Whether this subscription actually entitles the tenant right now.
     *
     * Status alone would say yes to a subscription whose period ended last
     * month and which nothing has swept — the same trap the offer's
     * commercial window avoids. A lapse is a fact about the clock, so the
     * clock is what is asked.
     *
     * A null period end means open-ended, not expired: a CUSTOM billing
     * period has no computable end, and it runs until someone ends it.
     */
    public function isLiveAt(DateTimeImmutable $moment): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }

        return $this->currentPeriodEnd === null || $moment < $this->currentPeriodEnd;
    }

    /**
     * Scheduled to end, but still entitling the tenant until it does. A
     * customer who cancels on day 2 of a month they paid for keeps the month.
     */
    public function isEndingAt(DateTimeImmutable $moment): bool
    {
        return $this->cancelAtPeriodEnd && $this->isLiveAt($moment);
    }
}
