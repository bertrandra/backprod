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

    /**
     * An invoice raised against this subscription went unpaid past the date
     * the product's schedule gives it (2026-09-27, spec §5.1).
     *
     * **A status and not an indicator**, unlike `cancel_at_period_end` and
     * `pending_offer_version_id` beside it, and §2.2 gives the rule that
     * separates them: those two describe a *future* ending and leave the
     * current rights entirely intact, so putting them in `status` would make
     * `status` lie about the access. This one describes the access — it is
     * suspended — so it belongs there, and the first code to read `status` and
     * decide gets the right answer.
     */
    public const PAST_DUE = 'PAST_DUE';
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
        /**
         * A move to another offer, waiting for the end of the paid period
         * (2026-09-27, spec §4). Null when none is waiting — and never set
         * at the same time as `cancelAtPeriodEnd`, which the database
         * refuses: a subscription has one ending.
         */
        public readonly ?PendingChange $pending = null,
        /**
         * Whether this was taken out on a freemium offer (2026-09-27,
         * spec §6.4) — snapshotted at subscription, like the terms, and never
         * recomputed from the offer version, which says what that plan is
         * today rather than what was sold.
         *
         * It stays true after a move up to a paid plan: the right to a free
         * period has been used, and `subscriptions_one_freemium_ever` is what
         * holds somebody to that, whatever the status of the row since.
         */
        public readonly bool $isFreemium = false,
        /**
         * When this was declared in arrears, and which unpaid invoice did it
         * (2026-09-27, spec §5.1). Both null together, which the database
         * enforces: a date with no document is a suspension nothing can lift.
         *
         * They survive the subscription: a row cancelled while owed for keeps
         * them, because "it was in arrears when it died" is a fact and nothing
         * reconstructs it from what remains.
         */
        public readonly ?DateTimeImmutable $pastDueSince = null,
        public readonly ?string $pastDueInvoiceId = null,
    ) {
    }

    /**
     * Suspended for non-payment (spec §5.1).
     *
     * Suspended and not restricted, which the operator decided on 2026-09-26:
     * no read-only tier and no half measure. "Restricted" would have required
     * deciding *what stays open*, product by product — a question with no
     * general answer, which would have to be re-asked for every new product,
     * and to which an oversight answers "open".
     *
     * What is shut is the workshop. The documents stay reachable, or the door
     * of the screen the customer came to pay at would be the one closed.
     */
    public function isPastDue(): bool
    {
        return $this->status === self::PAST_DUE;
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
     * The commitment this subscription carries once it moves to other terms.
     *
     * **This is the exception to the rule written beside it.** Everything
     * else a subscription snapshots — the term, the cancellation policy, the
     * renewal, the early-termination rule, the notice — is re-copied from the
     * new offer version when the plan changes, because the subscription must
     * describe the offer it is on and not the one it left (spec §1c, §3.2).
     * The commitment is not, and deliberately:
     *
     * **A change of plan is not a new contract.** A customer committed for
     * twenty-four months who moves up a tier stays committed until *their
     * original date*; they do not re-commit for another twenty-four without
     * having said so. That is the same sentence §13.1 writes about renewal —
     * "the commitment does not silently re-arm" — applied to the one other
     * moment the terms are rewritten.
     *
     * And it cuts both ways: **the new offer's commitment applies only if it
     * ends later.** Taking the arriving offer's commitment whenever it is
     * shorter would let a customer walk out of two years by moving to a plan
     * sold without one, which is the same mistake in the other direction.
     */
    public function commitmentAfterMovingTo(SubscriptionTerms $offered, DateTimeImmutable $at): Commitment
    {
        $held = new Commitment($this->terms->commitmentMonths, $this->commitmentEndsAt);
        $arriving = Commitment::sold($offered, $at);

        return $arriving->endsLaterThan($held) ? $arriving : $held;
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

    // `entitlesAt()` stood here until 2026-09-25 and said "a tenant
    // subscription entitles every member" — the rule ADR-053 removed. It had
    // no callers left once entitlement resolution became personal, and a
    // dead method stating a rule the platform has abandoned is worse than no
    // method: the next reader reaches for it. Who a subscription covers is
    // `Subscriptions::coversPerson()`, and what reaches somebody is the
    // entitlement repository's.

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
     *
     * **`PAST_DUE` is not live, and nothing here needed changing to make that
     * true** (2026-09-27, spec §5.1). The test is `status === ACTIVE`, so
     * introducing a fourth status suspended every entitlement that asks this
     * question, in one place, with no branch on the new word anywhere. That is
     * the argument for a status over a flag, and it is why `isHeldAt()` below
     * is the method that had to be added rather than this one.
     */
    public function isLiveAt(DateTimeImmutable $moment): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }

        return $this->currentPeriodEnd === null || $moment < $this->currentPeriodEnd;
    }

    /**
     * Still the subscription this tenant or person holds, entitling or not
     * (2026-09-27, spec §5.1).
     *
     * The question a *screen* asks, and the one the scope indexes ask: a
     * suspended subscription grants nothing and is nonetheless the customer's
     * subscription, the one the banner is about and the one they have to pay
     * before they may buy anything else. Answered `false` and the screen would
     * show "no subscription" to somebody who has one and owes for it — which
     * offers them a fresh purchase instead of the invoice.
     *
     * Distinct from `isLiveAt()` on purpose, and never a substitute for it:
     * entitlement asks that one. Two names because there are two questions,
     * and a single method serving both would have to decide which caller is
     * wrong.
     */
    public function isHeldAt(DateTimeImmutable $moment): bool
    {
        if ($this->status !== self::ACTIVE && $this->status !== self::PAST_DUE) {
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
