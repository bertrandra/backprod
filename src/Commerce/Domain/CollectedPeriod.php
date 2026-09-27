<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * What the period about to be cut short actually collected, and how much of
 * that may still go back (spec §3.3).
 *
 * Two numbers, and they are not the same number.
 *
 * `collectedMinorUnits` is the gross that moved: it is what the unconsumed
 * share is a share *of*. Prorating the offer's list price instead would be
 * arithmetic against a figure nobody paid — a discount, a repriced version or
 * a part-settled invoice each make the two differ, and the credit has to
 * describe money that exists.
 *
 * `returnableMinorUnits` is what is left after everything already given back:
 * the refunds already raised against that payment, and the credit notes
 * already raised against its invoice. Both bounds exist in the payment and
 * billing layers already — `REFUND_EXCEEDS_PAYMENT` and
 * `CREDIT_EXCEEDS_INVOICE` — and this is the same question asked *before* the
 * money is asked for, so a proration never has to be refused by one of them.
 *
 * **Nothing collected is not an error**, and this is the one place to say why
 * rather than the place to throw. A period nobody paid for has no unconsumed
 * value to return: a free plan, a €0 offer, an invoice still unpaid, or one
 * already credited in full — in each case the honest credit is zero, and the
 * reason travels with it so the answer is never silent. Refusing the change
 * instead would trap somebody on a plan they are not paying for, and crediting
 * anyway would give away money that never arrived.
 */
final class CollectedPeriod
{
    private function __construct(
        /** The payment the credit would be returned against; null when there is none. */
        public readonly ?string $paymentId,
        /** The gross that was collected for the current period. */
        public readonly int $collectedMinorUnits,
        /** Of that, what may still be given back. Never more than was collected. */
        public readonly int $returnableMinorUnits,
        public readonly string $currency,
        /** Why nothing may be returned, when nothing may. Null otherwise. */
        public readonly ?string $whyNothing,
    ) {
    }

    public static function of(
        string $paymentId,
        int $collectedMinorUnits,
        int $returnableMinorUnits,
        string $currency,
    ): self {
        $returnable = max(0, min($returnableMinorUnits, $collectedMinorUnits));

        return new self(
            $paymentId,
            max(0, $collectedMinorUnits),
            $returnable,
            strtoupper($currency),
            $returnable === 0
                ? 'Everything collected for this period has already been given back.'
                : null,
        );
    }

    /**
     * Nothing to give back, and the reason in words.
     *
     * The currency is still needed: the decision reports amounts, and a
     * decision with no currency would be a number nobody can render.
     */
    public static function nothing(string $currency, string $whyNothing): self
    {
        return new self(null, 0, 0, strtoupper($currency), $whyNothing);
    }

    public function hasSomethingToReturn(): bool
    {
        return $this->paymentId !== null && $this->returnableMinorUnits > 0;
    }
}
