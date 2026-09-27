<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\InvoiceRepository;
use App\Commerce\Domain\ChangeCredit;
use App\Commerce\Domain\CollectedPeriod;
use App\Commerce\Domain\Subscription;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\Refund;
use LogicException;

/**
 * The unconsumed part of a period, given back to the card that paid for it
 * (spec §3.3, ADR-058).
 *
 * The operator's decision, taken on 2026-09-26: a proration credit is **money
 * going back**, not a line on a document. The customer's statement shows it,
 * and no invoice has to carry a negative line to explain it. Which means every
 * proration is a refund — and a refund carries its credit note, which is the
 * half ADR-058 built precisely because this was coming.
 *
 * ## Which payment collected the period
 *
 * The most recent **settled** payment against an invoice of this subscription.
 * Not the subscription's first invoice, and not a balance the platform keeps:
 * every immediate change of plan raises its own invoice and resets the anchor,
 * so the newest settled payment is the one that bought the period now being cut
 * short. That is what makes a chain of upgrades work by construction (§3.4)
 * rather than by arithmetic on a running credit.
 *
 * `payments.subscription_id` cannot be used for this, and the reason is worth
 * writing down: a seat's first invoice is raised *before* the subscription
 * exists — the money is what starts it — so that payment's `subscription_id` is
 * null and only the invoice is ever linked back. The question therefore has to
 * be asked through the invoice.
 *
 * ## Why nothing collected is an answer and not an error
 *
 * A period nobody paid for has no unconsumed value. A €0 offer, a free plan, an
 * invoice still unpaid, an invoice already credited in full: in each case the
 * honest credit is zero, and {@see CollectedPeriod} carries the reason so that
 * the decision says it rather than swallowing it. The two alternatives are both
 * wrong — refusing the change would trap somebody on a plan nobody is paying
 * for, and crediting anyway would return money that never arrived.
 *
 * The bound is the same one the two layers below already enforce
 * (`REFUND_EXCEEDS_PAYMENT`, `CREDIT_EXCEEDS_INVOICE`), asked here *before* the
 * money is requested rather than discovered when it is refused.
 */
final class CreditTheUnconsumedPeriod implements ChangeCredit
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly Payments $refunds,
    ) {
    }

    public function collectedFor(Subscription $subscription): CollectedPeriod
    {
        $currency = $subscription->offer->version->currency;

        $payment = $this->payments->latestSettledForSubscription(
            $subscription->tenantId,
            $subscription->productId,
            $subscription->id,
        );

        if ($payment === null) {
            return CollectedPeriod::nothing(
                $currency,
                'Nothing has been collected for the current period, so there is no unconsumed value to give back.',
            );
        }

        $invoice = $this->invoices->find($subscription->tenantId, $subscription->productId, $payment->invoiceId);

        if ($invoice === null) {
            // A payment whose invoice cannot be read is not something to guess
            // around: the credit note is raised against that invoice, and
            // without it there is no document to declare the reversal on.
            return CollectedPeriod::nothing(
                $currency,
                'The document the current period was paid against cannot be read, so nothing can be credited.',
            );
        }

        $returnable = min(
            // What the provider could still send back.
            $payment->amount->minorUnits - self::alreadyReturned($this->payments->refundsOf($payment->id)),
            // What the invoice could still declare. Reversing the same VAT
            // twice would declare a negative sale that never happened.
            $invoice->gross->minorUnits - $this->creditNotes->creditedOn($invoice->id),
        );

        return CollectedPeriod::of(
            $payment->id,
            $payment->amount->minorUnits,
            $returnable,
            $payment->amount->currency,
        );
    }

    public function giveBack(
        Subscription $subscription,
        CollectedPeriod $collected,
        int $amountMinorUnits,
        ?string $actorUserId,
    ): string {
        $paymentId = $collected->paymentId;

        if ($paymentId === null || $amountMinorUnits <= 0) {
            // Unreachable through the decision, which never asks for a credit
            // it has not already found the money for. Stated rather than
            // assumed, because a refund of nothing against nobody would be a
            // provider call with no meaning.
            throw new LogicException('A proration credit was asked for with nothing to return it against.');
        }

        // `REQUESTED` and not a word of its own: it is money this platform
        // chose to send, which is what that reason means. A chargeback is the
        // one the customer's bank decided, and the difference matters to a
        // report about disputes.
        $refund = $this->refunds->refund(
            $subscription->tenantId,
            $subscription->productId,
            $paymentId,
            $amountMinorUnits,
            Refund::REQUESTED,
            $actorUserId,
        );

        return $refund->id;
    }

    /**
     * Everything already sent back against a payment.
     *
     * A failed refund is not money returned — the provider refused it — so it
     * does not bound the next one. The same rule {@see Payments::refund} uses,
     * because this is the same question asked one moment earlier.
     *
     * @param list<Refund> $refunds
     */
    private static function alreadyReturned(array $refunds): int
    {
        $total = 0;

        foreach ($refunds as $refund) {
            if ($refund->status !== Refund::FAILED) {
                $total += $refund->amount->minorUnits;
            }
        }

        return $total;
    }
}
