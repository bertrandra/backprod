<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Service\SupplierIdentity;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Service\Catalogue;
use App\Sales\Domain\Order;
use App\Sales\Domain\OrderFulfilment;
use App\Shared\Exceptions\ConflictException;
use DateTimeImmutable;

/**
 * The invoice is raised when the order is fulfilled; the subscription starts
 * when that invoice is paid.
 *
 * The two halves resolve the offer differently, and the difference is the
 * whole point of the gate.
 *
 * Invoicing asks what is **on sale**: the customer is about to be billed, and
 * billing them against terms that have since been withdrawn or repriced would
 * charge them for something they never agreed to. Refusing at that moment
 * costs nobody anything, because no money has moved.
 *
 * Activating asks what was **sold**: the money has arrived, possibly days
 * later and possibly after the offer was withdrawn. Refusing there would take
 * a customer's payment and give them nothing — so it activates on the version
 * the order recorded, which is the version the invoice priced.
 *
 * Both run through the *participating* repository methods, because both are
 * called from inside a transaction the caller already holds.
 */
final class InvoiceThenSubscribe implements OrderFulfilment
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly BillingProfileRepository $profiles,
        private readonly Catalogue $catalogue,
        private readonly SupplierIdentity $supplier,
    ) {
    }

    public function invoice(Order $order): array
    {
        $profile = $this->profiles->find($order->tenantId);

        if ($profile === null) {
            // Refused before anything is written, as when invoicing a
            // subscription directly: numbering is gapless, so a document
            // raised by mistake cannot be deleted.
            throw new ConflictException(
                'BILLING_PROFILE_REQUIRED',
                'This tenant has no billing profile, so no order can be invoiced to it.',
            );
        }

        $supplier = $this->supplier->forProduct($order->productId);
        $offer = SubscribedOffer::from(
            $this->catalogue->offerOnSale($order->productId, $this->offerIdFor($order)),
        );

        $now = new DateTimeImmutable();

        $invoice = $this->invoices->applyIssue(
            $order->tenantId,
            $order->productId,
            null,
            $order->lines,
            $supplier,
            $profile->snapshot(),
            SupplierIdentity::jurisdictionOf($supplier),
            $now,
            $offer->version->periodEndFrom($now),
            'Payable on receipt.',
            null,
        );

        return [
            'invoice_id' => $invoice->id,
            // Nothing to collect is not the same as nothing to do: the
            // invoice still exists, because a €0 document is still the record
            // of what was sold. It simply has no payment to wait for.
            'awaiting_payment' => $order->gross->minorUnits > 0,
        ];
    }

    public function activate(Order $order): string
    {
        $offer = SubscribedOffer::from(
            $this->catalogue->offerAsSold($order->productId, $order->offerVersionId),
        );

        $now = new DateTimeImmutable();

        $subscription = $this->subscriptions->applyActivate(
            $order->tenantId,
            $order->productId,
            $offer,
            $offer->version->periodEndFrom($now),
            null,
        );

        // The invoice was raised before the subscription existed, so it could
        // not name it then. It can now — and an invoice that names the
        // subscription it started is what makes "what has this subscription
        // been billed?" answerable without going through the order.
        if ($order->invoiceId !== null) {
            $this->invoices->applyAttachSubscription($order->invoiceId, $subscription->id);
        }

        return $subscription->id;
    }

    /**
     * The catalogue is addressed by offer, the order records the version.
     *
     * Resolving through the offer rather than storing it twice keeps one
     * source of truth for which offer a version belongs to; the version the
     * order recorded is what pins the terms, and the guard below is what
     * stops those two drifting apart silently.
     */
    private function offerIdFor(Order $order): string
    {
        foreach ($this->catalogue->offersOnSale($order->productId) as $candidate) {
            if ($candidate->currentVersion?->id === $order->offerVersionId) {
                return $candidate->id;
            }
        }

        // The offer was withdrawn, or re-versioned, between the order being
        // placed and being invoiced. Refusing is right: invoicing against
        // whatever the offer became would bill the customer for terms they
        // never agreed to.
        throw new ConflictException(
            'OFFER_NO_LONGER_ON_SALE',
            'The terms this order was placed on are no longer on sale.',
            ['offer_version_id' => $order->offerVersionId],
        );
    }
}
