<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\Money;
use App\Billing\Service\WhoSellsAndWhoBuys;
use App\Commerce\Domain\RenewalCharge;
use App\Commerce\Domain\Subscription;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * The next paid period, billed as an invoice like any other sale (ADR-068).
 *
 * Not an adjustment, not a line appended to something else: a document of its
 * own, numbered, dated and taxed, because that is what the customer is being
 * asked to pay and what the accounts will have to show. `renew()` extended the
 * period and the entitlements and raised nothing, which would have given away
 * every period after the first the moment anything renewed unattended.
 *
 * It is the sibling of {@see ChargeOnEarlyTermination} and deliberately shaped
 * like it, so the two cannot drift about who sells, who buys and how the tax is
 * decided. Three decisions carry over unchanged, and each matters here:
 *
 * **One period, at the price the customer holds.** `priceMinorUnits` comes from
 * the offer **version the subscription snapshotted**, never from the offer as it
 * stands today. Repricing an offer must not change a condition a customer
 * already agreed to (§13.1), and a renewal is where that promise is actually
 * tested — it is the first document raised after the catalogue has had time to
 * move.
 *
 * **The rate is decided now, and the customer is who they are now.** This is a
 * supply happening today, so §25.3 applies to it today rather than the rate the
 * first period was billed at. A rate change between two periods belongs to the
 * period it falls in.
 *
 * **Whoever sold the thing bills for continuing it.** The same decision as the
 * sale's, from the same place (`WhoSellsAndWhoBuys`), so a seat's renewal is the
 * organisation invoicing its own person — in its own series, with its own gapless
 * numbering (§25.3).
 *
 * **The invoice's period is the service's period**, which is what makes it
 * explicable: it starts where the last one ended, not at the moment cron
 * happened to fire, and it ends where the new one does.
 */
final class ChargeOnRenewal implements RenewalCharge
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WhoSellsAndWhoBuys $parties,
        private readonly Taxation $taxation,
        private readonly RenewalPaymentRequest $request,
    ) {
    }

    public function applyRenewal(
        Subscription $subscription,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): string {
        $version = $subscription->offer->version;

        $parties = $this->parties->forSale(
            $subscription->tenantId,
            $subscription->productId,
            $subscription->subscriberUserId,
        );

        // One moment for the whole charge. Reading the clock twice could land
        // the fiscal fact on the far side of a rate window from the line it is
        // supposed to describe.
        $now = new DateTimeImmutable();

        $calculation = $this->taxation->calculateSale(
            $parties->sale,
            $version->priceMinorUnits,
            $version->currency,
            null,
            $now,
        );

        $line = InvoiceLine::of(
            1,
            sprintf(
                '%s — subscription, %s to %s',
                $subscription->offer->name,
                $periodStart->format('Y-m-d'),
                $periodEnd->format('Y-m-d'),
            ),
            // One period, because that is what is being sold. The quantity is
            // never a number of months: the agreed price is per period, and a
            // yearly price times twelve is a figure on no contract (§13.1).
            1,
            Money::of($version->priceMinorUnits, $version->currency),
            Money::zero($version->currency),
            $calculation->rateBasisPoints,
            $version->id,
        );

        // Derived from the line just built rather than recomputed from a total:
        // the document is the fact.
        $facts = $this->taxation->factsForSale($parties->sale, [$line], $now);

        $supplyType = $parties->sale->supplier->defaultSupplyType;

        $invoice = $this->invoices->applyIssue(
            $subscription->tenantId,
            $subscription->productId,
            $parties->issuerTenantId,
            $subscription->id,
            [$line],
            $parties->from,
            $parties->to,
            $parties->jurisdiction,
            $periodStart,
            $periodEnd,
            // Due when the period it pays for starts, not when it was raised
            // (2026-10-05). It is raised `lead_days` early so the customer has
            // time to pay; "payable on receipt" would have had the collection
            // schedule chase it the next morning and suspend a subscription
            // inside a period already paid for.
            sprintf('Payable by %s.', $periodStart->format('Y-m-d')),
            $actorUserId,
            // Still inside the renewal's transaction, so the period, the
            // invoice, its fiscal fact and the request to pay it commit
            // together or not at all.
            function (Invoice $issued) use ($subscription, $parties, $supplyType, $now, $facts): void {
                $this->taxation->recordFor(
                    $subscription->tenantId,
                    $subscription->productId,
                    $parties->issuerTenantId,
                    $issued->id,
                    null,
                    $supplyType,
                    $now,
                    $facts,
                );

                $this->request->ask($subscription, $issued);
            },
            $periodStart,
        );

        return $invoice->id;
    }
}
