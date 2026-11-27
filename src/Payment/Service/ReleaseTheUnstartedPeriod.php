<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Billing\Domain\InvoiceStatus;
use App\Billing\Service\Invoicing;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\UnstartedPeriod;
use App\Commerce\Domain\UnstartedPeriodRelease;
use App\Payment\Domain\Refund;
use Closure;

/**
 * Withdraws the invoice of a period cancelled before it began (ADR-079).
 *
 * The operator's decision of 2026-11-27: *cancel the invoice, credit the card
 * with the amount in question, and raise the documents that needs.* Nothing
 * here is a special path — the cancellation is {@see Invoicing::cancel}, with
 * the VAT reversal every cancelled invoice writes, and the refund is
 * {@see Payments::refund}, with the credit note every refund carries.
 *
 * It lives beside the refunds for the reason the proration credit does: giving
 * money back is what this layer knows how to do.
 */
final class ReleaseTheUnstartedPeriod implements UnstartedPeriodRelease
{
    /**
     * @param Closure(): Invoicing $invoicing asked for when first needed, not
     *                                        when this is built: invoicing reads
     *                                        subscriptions, and subscriptions
     *                                        hold this, so building it eagerly
     *                                        is a cycle the container refuses
     */
    public function __construct(
        private readonly Closure $invoicing,
        private readonly Payments $payments,
    ) {
    }

    public function giveBack(Subscription $subscription, UnstartedPeriod $period, ?string $actorUserId): UnstartedPeriod
    {
        if ($period->invoiceId === null) {
            return $period;
        }

        $invoice = ($this->invoicing)()->show($subscription->tenantId, $subscription->productId, $period->invoiceId);

        if ($invoice->status === InvoiceStatus::CANCELLED) {
            // Withdrawn already — a cancellation retried after it had got this far.
            return $period->withOutcome(UnstartedPeriod::CANCELLED);
        }

        $payment = $this->payments->latestFor($subscription->tenantId, $subscription->productId, $invoice->id);

        if ($payment !== null && $payment->isSettled()) {
            // Paid for a period that will not happen: all of it goes back,
            // and the refund chain raises the credit note that makes the
            // fiscal fact follow the money (ADR-058).
            $this->payments->refund(
                $subscription->tenantId,
                $subscription->productId,
                $payment->id,
                null,
                Refund::REQUESTED,
                $actorUserId,
            );

            return $period->withOutcome(UnstartedPeriod::REFUNDED);
        }

        if ($invoice->status !== InvoiceStatus::ISSUED) {
            // Refunded before, credited, or settled by hand with no payment to
            // return it through. Nothing this can withdraw; the cancellation
            // still ends the subscription with the period running, and what
            // was paid by transfer is somebody's decision to return.
            return $period;
        }

        // Unpaid. An attempt still open would settle the document after it
        // was cancelled, so it is abandoned at the provider first — and if the
        // provider says that money is already coming, this refuses, which is
        // the right answer: the customer cancels again once it has landed and
        // is refunded.
        $this->payments->abandonAttempts($subscription->tenantId, $subscription->productId, $invoice->id, $actorUserId);

        return $period;
    }

    public function applyCancel(Subscription $subscription, UnstartedPeriod $period, ?string $actorUserId): UnstartedPeriod
    {
        if ($period->invoiceId === null || $period->outcome !== UnstartedPeriod::NOTHING_RAISED) {
            return $period;
        }

        $invoice = ($this->invoicing)()->show($subscription->tenantId, $subscription->productId, $period->invoiceId);

        if ($invoice->status !== InvoiceStatus::ISSUED) {
            return $period;
        }

        // On the caller's transaction: the nested one joins it, so the
        // release and the withdrawal of what it would have cost commit
        // together or not at all.
        ($this->invoicing)()->cancel($subscription->tenantId, $subscription->productId, $invoice->id, $actorUserId);

        return $period->withOutcome(UnstartedPeriod::CANCELLED);
    }
}
