<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Whether a product's subscriptions roll into their next paid period by
 * themselves, and how early (ADR-068).
 *
 * **What this is not.** It is not tacit renewal of a *term*. Those are two
 * different renewals and conflating them is the expensive mistake:
 *
 * ```text
 * current_period_end   the paid period rolls      the contract running
 * term_ends_at         the term rolls             a new contract, tacitly
 * ```
 *
 * A 24-month subscription billed monthly is one commitment billed 24 times
 * (§13.1). Rolling period 7 into period 8 is the agreed contract doing what it
 * says; rolling the *term* into a second 24 months is a fresh commitment, which
 * consumer law requires be preceded by a notice whose deadline
 * {@see \App\Job\Service\SendRenewalNotices} says is unconfirmed, especially
 * toward consumers where it varies by member state. So this rolls periods
 * **inside** a term and stops at it, and the term stays a decision somebody
 * takes.
 *
 * **Off unless the operator says otherwise.** Absent configuration means no
 * automatic renewal, which is exactly today's behaviour — a deployment upgrading
 * into this changes nothing until somebody chooses. The reason is this setting's
 * own, as ADR-063's was: what silence would cost here is a customer billed for a
 * period nobody decided to sell them, so the absence of a decision is the
 * absence of the charge.
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
     * How early a period may be rolled, in days.
     *
     * Two: enough that a night of broken cron costs nobody their subscription,
     * short enough that the invoice is recognisably for the period about to
     * start. A default and not a rule.
     */
    public const DEFAULT_LEAD_DAYS = 2;

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
     * This product's policy, or none at all when it has said nothing.
     *
     * An unusable document — not an object, a lead outside the bounds, a flag
     * that is not a flag — is treated exactly as no document. The alternative is
     * to bill on a value nobody can read, and that is not a decision an
     * operator made.
     *
     * @param array<string, mixed> $configuration every setting of the product, decoded
     */
    public static function fromConfiguration(array $configuration): self
    {
        $document = $configuration[self::CONFIGURATION_KEY] ?? null;

        if (!is_array($document)) {
            return self::off();
        }

        // Strictly true. A string "false" is what a hand-edited document
        // produces, and reading it as truthy would turn a typo into money.
        if (($document['automatic'] ?? null) !== true) {
            return self::off();
        }

        $lead = $document['lead_days'] ?? self::DEFAULT_LEAD_DAYS;

        if (!is_int($lead) || $lead < 1 || $lead > self::LONGEST_LEAD) {
            return self::off();
        }

        return new self(true, $lead);
    }

    public static function off(): self
    {
        return new self(false, self::DEFAULT_LEAD_DAYS);
    }

    /**
     * @return array{automatic: bool, lead_days: int}
     */
    public function toArray(): array
    {
        return ['automatic' => $this->automatic, 'lead_days' => $this->leadDays];
    }
}
