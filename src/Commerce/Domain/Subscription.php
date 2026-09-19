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
 *
 * §13.1 adds three more durations that are also not each other — the total
 * term, the commitment, and the notice — carried by {@see SubscriptionTerms}
 * and snapshotted from the offer version when the subscription is taken out.
 * The one worth restating: **the payment period is not the commitment.** A
 * 24-month subscription billed monthly is one 24-month commitment billed 24
 * times, and a model that confuses them lets a customer leave after one.
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
        public readonly Subscriber $subscriber,
        public readonly SubscriptionTerms $terms,
        public readonly string $status,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $currentPeriodStart,
        public readonly ?DateTimeImmutable $currentPeriodEnd,
        public readonly bool $cancelAtPeriodEnd,
        public readonly ?DateTimeImmutable $cancelledAt,
        public readonly ?DateTimeImmutable $endedAt,
        public readonly ?DateTimeImmutable $termEndsAt = null,
        public readonly ?DateTimeImmutable $commitmentEndsAt = null,
        public readonly ?DateTimeImmutable $cancelEffectiveAt = null,
        /** Who activated it (2026-09-19): the person a seat is for, or the administrator who bought the organisation's. */
        public readonly ?string $ownerUserId = null,
    ) {
    }

    /**
     * Whether a commitment still binds at this moment.
     *
     * The clock decides, as it does for offers, entitlements, quote expiry,
     * job leases and VAT rates. A commitment that has run out stops binding
     * whether or not anything swept it — which is why nothing needs to.
     */
    public function isUnderCommitmentAt(DateTimeImmutable $moment): bool
    {
        if (!$this->terms->hasCommitment() || $this->commitmentEndsAt === null) {
            return false;
        }

        return $moment < $this->commitmentEndsAt;
    }

    /**
     * Whether a scheduled cancellation falls at or before a moment.
     *
     * The question renewal has to ask. A cancellation deferred to a
     * commitment ending ten months out must not stop next month's renewal;
     * one due at the end of the period being renewed must stop it, or the
     * subscription is quietly extended past the date the customer was given.
     */
    public function isDueToEndBy(DateTimeImmutable $moment): bool
    {
        return $this->cancelEffectiveAt !== null && $this->cancelEffectiveAt <= $moment;
    }

    /**
     * Whether the term has run out. Open-ended subscriptions never have.
     */
    public function hasReachedTermAt(DateTimeImmutable $moment): bool
    {
        return $this->termEndsAt !== null && $moment >= $this->termEndsAt;
    }

    /**
     * Whether this subscription entitles the given person.
     *
     * A tenant subscription entitles every member; a seat entitles one. Live
     * *and* addressed to them — the two questions are separate and both have
     * to be yes.
     */
    public function entitlesAt(string $userId, DateTimeImmutable $moment): bool
    {
        return $this->isLiveAt($moment) && $this->subscriber->entitles($userId);
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
