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
 * What an order causes: the subscription starts and the invoice is raised.
 *
 * Both happen through the *participating* repository methods, because this
 * runs inside the transaction that completes the order. The schema refuses a
 * completed order that does not name both, so the three writes are one thing
 * or they are a sale nobody can trace.
 *
 * The invoice's lines are copied from the order's, which were copied from the
 * quote's. That is §25's snapshot rule applied along the whole chain: what
 * was quoted is what was ordered is what is billed, and none of it is
 * re-derived from an offer that may have moved since.
 */
final class SubscribeAndInvoice implements OrderFulfilment
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly InvoiceRepository $invoices,
        private readonly BillingProfileRepository $profiles,
        private readonly Catalogue $catalogue,
        private readonly SupplierIdentity $supplier,
    ) {
    }

    public function fulfil(Order $order): array
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
        $periodEnd = $offer->version->periodEndFrom($now);

        $subscription = $this->subscriptions->applyActivate(
            $order->tenantId,
            $order->productId,
            $offer,
            $periodEnd,
            null,
        );

        $invoice = $this->invoices->applyIssue(
            $order->tenantId,
            $order->productId,
            $subscription->id,
            $order->lines,
            $supplier,
            $profile->snapshot(),
            SupplierIdentity::jurisdictionOf($supplier),
            $now,
            $periodEnd,
            'Payable on receipt.',
            null,
        );

        return ['subscription_id' => $subscription->id, 'invoice_id' => $invoice->id];
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
        // placed and being fulfilled. Refusing is right: fulfilling against
        // whatever the offer became would bill the customer for terms they
        // never agreed to.
        throw new ConflictException(
            'OFFER_NO_LONGER_ON_SALE',
            'The terms this order was placed on are no longer on sale.',
            ['offer_version_id' => $order->offerVersionId],
        );
    }
}
