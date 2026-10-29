<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Whether a product's subscriptions roll into their next paid period by
 * themselves, and how early (ADR-068).
 *
 * **Two renewals, and this switches both.** They are different renewals and
 * conflating them is the expensive mistake:
 *
 * ```text
 * current_period_end   the paid period rolls      the contract running
 * term_ends_at         the term rolls             a new contract, tacitly
 * ```
 *
 * A 24-month subscription billed monthly is one commitment billed 24 times
 * (§13.1). Rolling period 7 into period 8 is the agreed contract doing what it
 * says; rolling the *term* into a second 24 months is the contract agreed
 * again by not refusing it, which is only fair once the customer was told. So
 * the term rolls too, since ADR-071, but only for an offer sold `AUTO_RENEW`
 * and only once the pre-renewal notice went out at least `notice_days` before
 * ({@see \App\Commerce\Domain\TermRenewal}).
 *
 * **On unless the operator switches it off** (ADR-071, 2026-10-29). This was
 * off by default, on the reasoning that silence should not bill anybody. The
 * operator chose the other baseline: a subscription is sold as continuing, so
 * the absence of a decision is the subscription continuing, and stopping that
 * for a product is the decision. Only `"automatic": false`, strictly, switches
 * it off; a document nobody can read is no decision, so it leaves the
 * baseline.
 *
 * **It renews before the period ends, not after.** The first design renewed what
 * had already lapsed, and that puts this job in a race with `sweep.subscriptions`
 * over the same rows — both run daily, the sweep expires exactly what
 * `current_period_end < now()` selects, and whichever won decided whether a
 * customer kept their subscription. Renewing inside a lead window removes the
 * race rather than ordering it: a subscription still inside its paid period is
 * not lapsed, so the sweep never sees it. It also bills the period before it
 * starts, which is what a continuous subscription and "payable on receipt" both
 * already mean.
 *
 * The price of that choice, stated rather than discovered: cron down for longer
 * than the lead lapses the subscription, and the sweep then expires it. That is
 * an operational failure the operator must fix in any case — `preflight` reports
 * a stopped cron, and `renew()` is still reachable by hand — and it is the
 * honest end for a period nobody billed and nobody served.
 */
final class RenewalPolicy
{
    /**
     * @see \App\Product\Domain\ProductSettings — keys are named by whoever
     *      reads them, so a typo stores configuration nothing consults.
     */
    public const CONFIGURATION_KEY = 'renewal';

    /**
     * How early a period may be rolled, in days — which is also how early the
     * customer is asked to pay for it.
     *
     * Seven, the operator's figure (2026-10-05): the invoice and the mail
     * carrying it go out a week before the period starts, so somebody paying by
     * transfer has the time to, and the invoice is due on the day the period
     * starts rather than the day it was raised. It was two while the renewal
     * only had to survive a night of broken cron. A default and not a rule — the
     * console sets it per product.
     */
    public const DEFAULT_LEAD_DAYS = 7;

    /**
     * A lead of a year would bill next year's period today, and one of zero
     * would make the whole thing depend on cron firing in the right second.
     * Both ends are bounded so that a mistyped document reads as unusable
     * rather than as a policy.
     */
    public const LONGEST_LEAD = 30;

    private function __construct(
        public readonly bool $automatic,
        public readonly int $leadDays,
    ) {
    }

    /**
     * This product's policy, or the baseline when it has said nothing.
     *
     * An unusable part of the document — a flag that is not a flag, a lead
     * outside the bounds — is treated as that part unsaid, and so as the
     * baseline: renewing, seven days ahead. Only a strict `false` is the
     * operator switching it off (ADR-071).
     *
     * @param array<string, mixed> $configuration every setting of the product, decoded
     */
    public static function fromConfiguration(array $configuration): self
    {
        $document = $configuration[self::CONFIGURATION_KEY] ?? null;

        if (!is_array($document)) {
            return self::baseline();
        }

        $lead = $document['lead_days'] ?? self::DEFAULT_LEAD_DAYS;

        if (!is_int($lead) || $lead < 1 || $lead > self::LONGEST_LEAD) {
            $lead = self::DEFAULT_LEAD_DAYS;
        }

        return new self(($document['automatic'] ?? null) !== false, $lead);
    }

    /** What a product that has said nothing does: it renews, a week ahead. */
    public static function baseline(): self
    {
        return new self(true, self::DEFAULT_LEAD_DAYS);
    }

    public static function off(): self
    {
        return new self(false, self::DEFAULT_LEAD_DAYS);
    }

    /**
     * A policy an operator chose, or null when the lead is outside the bounds.
     *
     * Null rather than an exception so the caller names the field it refuses:
     * this class knows the bounds, the controller knows the request.
     */
    public static function chosen(bool $automatic, int $leadDays): ?self
    {
        if ($leadDays < 1 || $leadDays > self::LONGEST_LEAD) {
            return null;
        }

        return new self($automatic, $leadDays);
    }

    /**
     * @return array{automatic: bool, lead_days: int}
     */
    public function toArray(): array
    {
        return ['automatic' => $this->automatic, 'lead_days' => $this->leadDays];
    }
}
