<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Service\InvoiceCancellation;
use App\Commerce\Domain\ChangeCredit;
use App\Commerce\Domain\CollectedPeriod;
use App\Commerce\Domain\Subscription;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\Refund;
use DateTimeImmutable;
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
 * The settled payment of **the invoice whose period is being cut** (ADR-076).
 * It used to be the most recent settled payment against any invoice of the
 * subscription, on the reasoning that every change raises its own invoice and
 * resets the anchor. That held only while each invoice was paid as it was
 * raised: a renewal invoice raised days ahead and due at the period's start
 * (ADR-070), or an upgrade's invoice not yet paid, left the newest *settled*
 * payment one period behind — and a period already consumed was refunded
 * while the one being used stayed owed.
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
        private readonly InvoiceCancellation $cancellation,
    ) {
    }

    public function collectedFor(Subscription $subscription, DateTimeImmutable $now): CollectedPeriod
    {
        $currency = $subscription->offer->version->currency;

        // The two newest period invoices, newest first. Two, because the
        // newest may be one raised ahead for a period that has not begun.
        $invoices = $this->invoices->periodInvoicesOf(
            $subscription->tenantId,
            $subscription->productId,
            $subscription->id,
            2,
        );

        $newest = $invoices[0] ?? null;
        $superseded = null;

        if ($newest !== null && self::isOwed($newest) && $newest->periodStart !== null && $newest->periodStart > $now) {
            // Raised ahead by the renewal (ADR-070), unpaid, for a period on
            // the plan being left that will now never start. It is not the
            // period being cut — the one before it is — and the change
            // withdraws it rather than leave it owed and chased.
            $superseded = $newest->id;
            $newest = $invoices[1] ?? null;
        }

        if ($newest === null) {
            return CollectedPeriod::nothing(
                $currency,
                'Nothing has been collected for the current period, so there is no unconsumed value to give back.',
            )->superseding($superseded);
        }

        if (self::isOwed($newest)) {
            return CollectedPeriod::nothing(
                $currency,
                'The invoice for the current period has not been paid, so there is nothing to give back; it remains due.',
            )->superseding($superseded);
        }

        $payment = $this->payments->latestSettledForInvoice(
            $subscription->tenantId,
            $subscription->productId,
            $newest->id,
        );

        if ($payment === null) {
            // Settled some other way — a transfer reconciled by hand, a
            // credit in full — so there is no payment method to send a share
            // back to.
            return CollectedPeriod::nothing(
                $currency,
                'Nothing was collected by a payment method for the current period, so there is nothing to send back to one.',
            )->superseding($superseded);
        }

        $returnable = min(
            // What the provider could still send back.
            $payment->amount->minorUnits - self::alreadyReturned($this->payments->refundsOf($payment->id)),
            // What the invoice could still declare. Reversing the same VAT
            // twice would declare a negative sale that never happened.
            $newest->gross->minorUnits - $this->creditNotes->creditedOn($newest->id),
        );

        $collected = CollectedPeriod::of(
            $payment->id,
            $payment->amount->minorUnits,
            $returnable,
            $payment->amount->currency,
            $newest->id,
        )->superseding($superseded);

        // The row has been rolled into a period that has not started, and
        // this invoice is not that period's: it bought the one still running,
        // which ends where the row's begins. Without this the share would be
        // taken of a period that has not begun — "before the start" clamps to
        // everything — and a period almost consumed came back in full.
        if (
            $subscription->currentPeriodStart > $now
            && $newest->periodStart !== null
            && $newest->periodStart <= $now
        ) {
            return $collected->over($newest->periodStart, $subscription->currentPeriodStart);
        }

        return $collected;
    }

    public function withdrawSuperseded(Subscription $subscription, CollectedPeriod $collected, ?string $actorUserId): void
    {
        $invoiceId = $collected->supersedesInvoiceId;

        if ($invoiceId === null) {
            return;
        }

        // The provider first: an attempt that can still collect would pay a
        // document that no longer exists. One it cannot stop refuses here,
        // before anything has moved.
        $this->refunds->abandonAttempts($subscription->tenantId, $subscription->productId, $invoiceId, $actorUserId);

        $invoice = $this->invoices->find($subscription->tenantId, $subscription->productId, $invoiceId);

        if ($invoice !== null) {
            // Not through `Invoicing`, which depends on `Subscriptions` — the
            // service this is called from. The same rule, reached directly.
            $this->cancellation->cancel($invoice, $actorUserId);
        }
    }

    /**
     * Still to be paid, and for something: an invoice of nothing owes nothing
     * whatever its status says.
     */
    private static function isOwed(Invoice $invoice): bool
    {
        return $invoice->gross->minorUnits > 0 && InvoiceStatus::isPayable($invoice->status);
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
            // Once per period cut (ADR-076). The credit commits before the
            // plan moves, so a crash between the two is retried — and the
            // retry must find this refund rather than make a second one.
            sprintf('change:%s:%s', $subscription->id, $collected->invoiceId ?? $paymentId),
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
