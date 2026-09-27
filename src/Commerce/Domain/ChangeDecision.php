<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * What happens when somebody moves to another offer, and why (spec §3, §7).
 *
 * The same shape as {@see CancellationDecision}, and for the same reason
 * §13.1 gives: "can I change plan?" answered yes-or-no is useless to the
 * person asking. What they need is *when* it takes effect, *what it costs
 * today*, and *which rule decided that* — and a boolean carries none of the
 * three.
 *
 * It exists so one calculation answers two questions. `previewOfferChange`
 * renders it and `changeOffer` acts on it, exactly as `showSchedule` and
 * `cancelSubscription` share one `CancellationPolicy`: a preview computed by
 * a different code path from the act is a preview that can be wrong, and the
 * screen would be quoting a figure the server never agreed to.
 *
 * **Three amounts, all gross, all integer minor units.** They describe two
 * separate movements of money and their difference:
 *
 * ```text
 * credit   the unconsumed part of the current period, going back on the card
 * charge   the new period, invoiced through the normal billing chain
 * net      charge − credit: what the move costs today, and negative when the
 *          credit is the larger of the two
 * ```
 *
 * `net` is arithmetic for the customer's benefit and **not** a document: the
 * credit is refunded and the charge is invoiced, each in full, because §3.3
 * decided the credit goes back to the payment method rather than onto an
 * invoice as a negative line. Netting them into one document would be the
 * thing that decision rejected.
 *
 * Gross throughout, because both numbers are money movements and a card is
 * charged gross. Mixing a pre-tax price with a refunded gross is how `net`
 * would come out wrong by exactly the VAT.
 */
final class ChangeDecision
{
    public const IMMEDIATE = 'IMMEDIATE';
    public const AT_PERIOD_END = 'AT_PERIOD_END';

    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly string $ruleId,
        /** UPGRADE, DOWNGRADE or LATERAL — from the plans' ranks, never their names (§13). */
        public readonly string $direction,
        public readonly ?string $effect,
        public readonly ?DateTimeImmutable $effectiveAt,
        public readonly string $currency,
        public readonly int $creditMinorUnits,
        public readonly int $chargeMinorUnits,
        public readonly int $netMinorUnits,
        /** When the period that the change opens would end. */
        public readonly ?DateTimeImmutable $newPeriodEnd,
        /**
         * The commitment after the move — which the anchor reset does not
         * touch (§3.3). Reported because "does upgrading re-commit me?" is
         * the question a customer under commitment actually asks, and a
         * screen that had to work it out would answer it twice.
         */
        public readonly ?DateTimeImmutable $commitmentEndsAt,
        public readonly array $reasons,
    ) {
    }

    /**
     * A move that takes effect now, priced (§3).
     *
     * @param list<string> $reasons
     */
    public static function now(
        string $ruleId,
        string $direction,
        DateTimeImmutable $at,
        string $currency,
        int $creditMinorUnits,
        int $chargeMinorUnits,
        ?DateTimeImmutable $newPeriodEnd,
        ?DateTimeImmutable $commitmentEndsAt,
        array $reasons,
    ): self {
        return new self(
            true,
            $ruleId,
            $direction,
            self::IMMEDIATE,
            $at,
            $currency,
            $creditMinorUnits,
            $chargeMinorUnits,
            // The one place this subtraction is done. A screen doing it would
            // be adding two amounts in the frontend, which §4 forbids.
            $chargeMinorUnits - $creditMinorUnits,
            $newPeriodEnd,
            $commitmentEndsAt,
            $reasons,
        );
    }

    /**
     * A move that waits for the end of the paid period (spec §4).
     *
     * Nothing to pay and nothing to credit, because nothing is being cut
     * short: the customer keeps, entire, what they have already paid for.
     *
     * @param list<string> $reasons
     */
    public static function deferred(
        string $ruleId,
        string $direction,
        DateTimeImmutable $effectiveAt,
        string $currency,
        ?DateTimeImmutable $newPeriodEnd,
        ?DateTimeImmutable $commitmentEndsAt,
        array $reasons,
    ): self {
        return new self(
            true,
            $ruleId,
            $direction,
            self::AT_PERIOD_END,
            $effectiveAt,
            $currency,
            0,
            0,
            0,
            $newPeriodEnd,
            $commitmentEndsAt,
            $reasons,
        );
    }

    /**
     * @param list<string> $reasons
     */
    public static function refused(
        string $ruleId,
        string $direction,
        string $currency,
        array $reasons,
    ): self {
        return new self(false, $ruleId, $direction, null, null, $currency, 0, 0, 0, null, null, $reasons);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'rule_id' => $this->ruleId,
            'direction' => $this->direction,
            'effect' => $this->effect,
            'effective_at' => $this->effectiveAt?->format(DATE_ATOM),
            'currency' => $this->currency,
            'credit_minor_units' => $this->creditMinorUnits,
            'charge_minor_units' => $this->chargeMinorUnits,
            'net_minor_units' => $this->netMinorUnits,
            'new_period_end' => $this->newPeriodEnd?->format(DATE_ATOM),
            'commitment_ends_at' => $this->commitmentEndsAt?->format(DATE_ATOM),
            'reasons' => $this->reasons,
        ];
    }
}
