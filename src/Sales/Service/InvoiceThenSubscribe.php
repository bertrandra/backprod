<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Service\SupplierIdentity;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Service\Catalogue;
use App\Sales\Domain\Order;
use App\Sales\Domain\OrderFulfilment;
use App\Shared\Exceptions\ConflictException;
use App\Tax\Service\Taxation;
use App\User\Domain\UserRepository;
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
        private readonly Taxation $taxation,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * The person a seat is sold to (2026-09-19), as the customer on the
     * invoice: their name — or their address, for somebody who gave none —
     * copied in like every snapshot, so a later change of name or an
     * erasure (§26) leaves the document as it was sent. Falls back to the
     * organisation's identity when the person cannot be found, which is a
     * broken invariant rather than a case.
     *
     * @param array<string, mixed> $organisation
     *
     * @return array<string, mixed>
     */
    private function customerOf(Order $order, array $organisation): array
    {
        if ($order->subscriber->userId === null) {
            return $organisation;
        }

        $person = $this->users->find($order->subscriber->userId);

        if ($person === null) {
            return $organisation;
        }

        $name = $person->displayName ?? $person->email ?? 'A member';

        return [
            'legal_name' => $name,
            'billing_email' => $person->email,
            'country_code' => $organisation['country_code'] ?? null,
            'person' => ['name' => $name, 'email' => $person->email],
            'organisation' => $organisation['legal_name'] ?? null,
            // The language the document is issued in (ADR-050): theirs.
            'locale' => $person->locale,
        ];
    }

    public function invoice(Order $order, ?string $actorUserId = null): array
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

        // The order's lines were priced when the order was placed. The regime
        // has to be decided now — a rate window may have opened since, or the
        // customer's VAT number may have been verified — but the *amounts*
        // come from the lines, so the facts sum to the invoice by
        // construction. If the two no longer agree, this throws, and it
        // throws **before** anything is issued: numbering is gapless, so a
        // document raised in error cannot be deleted.
        $facts = $this->taxation->factsFor(
            $order->tenantId,
            $order->productId,
            $order->lines,
            $now,
        );

        $supplyType = $this->taxation->defaultSupplyType($order->productId);

        // Who sells and who buys (2026-09-19). The organisation's own
        // subscription is sold by the product's supplier to the organisation.
        // A seat is the organisation selling to one of its people: the
        // organisation's legal identity is the supplier, the person is the
        // customer, and the VAT jurisdiction is the organisation's country.
        [$from, $to, $jurisdiction] = $order->subscriber->isSeat()
            ? [$profile->snapshot(), $this->customerOf($order, $profile->snapshot()), $profile->countryCode ?? SupplierIdentity::jurisdictionOf($supplier)]
            : [$supplier, $profile->snapshot(), SupplierIdentity::jurisdictionOf($supplier)];

        $invoice = $this->invoices->applyIssue(
            $order->tenantId,
            $order->productId,
            null,
            $order->lines,
            $from,
            $to,
            $jurisdiction,
            $now,
            $offer->version->periodEndFrom($now),
            'Payable on receipt.',
            // Who raised it — and, for the organisation's own invoice, whose
            // language the document is issued in (ADR-050).
            $actorUserId,
            // Still inside the transaction this method was called in, so the
            // invoice, the fiscal fact, the subscription and the completed
            // order all commit together or none of them do.
            function (Invoice $issued) use ($order, $supplyType, $now, $facts): void {
                $this->taxation->recordFor(
                    $order->tenantId,
                    $order->productId,
                    $issued->id,
                    null,
                    $supplyType,
                    $now,
                    $facts,
                );
            },
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

        // The order's subscriber becomes the subscription's (§13.1): the
        // organisation, or the one person whose seat this is.
        //
        // And **whoever placed the order owns it** (2026-09-25). This passed
        // `null` until today, so every purchase made through quote → order →
        // payment started a subscription owned by nobody. That cost nothing
        // while an organisation's subscription entitled all its members; the
        // moment coverage stopped following membership (ADR-053) it meant a
        // bought subscription covered nobody at all — the buyer included.
        // `SalesChainTest` is what said so.
        $subscription = $this->subscriptions->applyActivate(
            $order->tenantId,
            $order->productId,
            $offer,
            $offer->version->periodEndFrom($now),
            $order->placedBy,
            $order->subscriber,
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
