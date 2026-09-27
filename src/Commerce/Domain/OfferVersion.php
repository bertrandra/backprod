<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * The terms of an offer at one point in its history: price, billing period,
 * commercial window, and what it grants.
 *
 * §12 requires a price or quota change to create a version rather than
 * rewrite history. A subscription therefore refers to a version, not to an
 * offer — otherwise what a tenant bought would change under them the next
 * time the price did.
 *
 * validFrom and validUntil are the window in which this version may be
 * *sold*. §12 is explicit that this is not the tenant's subscription period,
 * and the two are never conflated here.
 */
final class OfferVersion
{
    public const DRAFT = 'DRAFT';
    public const ACTIVE = 'ACTIVE';
    public const EXPIRED = 'EXPIRED';
    public const ARCHIVED = 'ARCHIVED';

    /**
     * @param list<OfferGrant> $grants
     */
    public function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $status,
        public readonly string $billingPeriod,
        public readonly int $priceMinorUnits,
        public readonly string $currency,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly array $grants,
        /**
         * What this version commits a subscriber to (§13.1). Defaulted to
         * open-ended so every offer written before terms existed keeps
         * meaning exactly what it meant: month-to-month, no commitment.
         */
        public readonly SubscriptionTerms $terms = new SubscriptionTerms(
            null,
            0,
            SubscriptionTerms::ANYTIME,
            SubscriptionTerms::AUTO_RENEW,
            SubscriptionTerms::FORBIDDEN,
            0,
        ),
    ) {
    }

    /**
     * Whether this version costs nothing.
     *
     * A property, and asked as one. "Is this the freemium?" written as a
     * comparison against a plan's code is exactly the shape `gate:plans`
     * forbids in PHP and §13 forbids everywhere: a name that means something
     * in one deployment's catalogue and nothing in another's. A price of zero
     * means the same thing in every catalogue there will ever be.
     */
    public function isFree(): bool
    {
        return $this->priceMinorUnits === 0;
    }

    /**
     * Whether this version is a **freemium** (spec §6): free, and over when
     * its period is.
     *
     * Both halves, and neither alone would do. A free offer that renews is a
     * free tier — somebody may hold it for years, and nothing about it is a
     * trial. An offer that ends at its term and costs money is an ordinary
     * fixed-term contract, invoiced like any other. Only the conjunction is
     * the thing this platform gives away once per account (§6.4), and only the
     * conjunction may be taken without an order, an invoice or a payment.
     */
    public function isFreemium(): bool
    {
        return $this->isFree() && $this->terms->endsAtTerm();
    }

    /**
     * When the period this version bills for would end, starting from a
     * moment.
     *
     * Null for a CUSTOM period, which has no computable end — meaning the
     * subscription runs until someone ends it, not that it has already
     * expired.
     *
     * It lives on the version because the billing period is the version's
     * own term. Two callers needed it (subscribing, and an order fulfilling
     * itself) and the second would otherwise have copied it.
     */
    public function periodEndFrom(DateTimeImmutable $from): ?DateTimeImmutable
    {
        return match ($this->billingPeriod) {
            'MONTHLY' => $from->modify('+1 month'),
            'YEARLY' => $from->modify('+1 year'),
            default => null,
        };
    }

    /**
     * How many whole billing periods a span of months covers, rounded up.
     *
     * An early-termination charge has to be priced in the unit that was
     * actually agreed. A commitment is counted in months, but what the
     * customer signed is a price *per billing period*, and multiplying a
     * yearly price by a number of months would invent a figure nobody quoted.
     *
     * A part-period rounds up, for the same reason a part-month does: the
     * period would have billed in full had the subscription run on.
     *
     * Null for a CUSTOM period — there is no period to count, so there is no
     * honest number here. The caller refuses rather than guesses.
     */
    public function periodsIn(int $months): ?int
    {
        if ($months <= 0) {
            return 0;
        }

        return match ($this->billingPeriod) {
            'MONTHLY' => $months,
            'YEARLY' => intdiv($months + 11, 12),
            default => null,
        };
    }

    /**
     * Whether this version may be sold at the given moment.
     *
     * Both halves matter. A version can be ACTIVE and outside its window
     * (published early, or past its end without anyone having run a job to
     * mark it EXPIRED), and status alone would sell it. Asking the clock
     * means expiry does not depend on a job having run.
     */
    public function isSellableAt(DateTimeImmutable $moment): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }

        if ($moment < $this->validFrom) {
            return false;
        }

        return $this->validUntil === null || $moment < $this->validUntil;
    }
}
