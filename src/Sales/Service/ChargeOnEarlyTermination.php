<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\Money;
use App\Billing\Service\WhoSellsAndWhoBuys;
use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\EarlyTerminationCharge;
use App\Commerce\Domain\Subscription;
use App\Shared\Exceptions\ConflictException;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * Leaving a commitment early costs what remains of it, and that is billed as
 * an invoice like any other sale (§13.1, §25).
 *
 * Not a fee, not an adjustment, not a line appended to next month: a document
 * of its own, numbered, dated and taxed, because that is what the customer is
 * being asked to pay and what the accounts will have to show. A charge that
 * exists only as a number in a subscription row is a charge nobody can
 * dispute, refund or declare.
 *
 * Two decisions are worth stating, because both could plausibly have gone the
 * other way.
 *
 * **Priced in billing periods, not months.** The commitment is counted in
 * months — that is the unit it was sold in — but the price the customer agreed
 * to is a price *per billing period*. Multiplying a yearly price by a number
 * of months would produce a figure that appears on no contract.
 *
 * **The rate is decided now, and the customer is who they are now.** This is
 * a supply happening today, so §25.3 applies to it today: the regime comes
 * from a motivated decision about this customer at this moment, not from
 * whatever rate the original subscription was billed at. A customer who has
 * since had their VAT number verified leaves under reverse charge.
 */
final class ChargeOnEarlyTermination implements EarlyTerminationCharge
{
    private const PAYMENT_TERMS = 'Payable on receipt.';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WhoSellsAndWhoBuys $parties,
        private readonly Taxation $taxation,
    ) {
    }

    public function applyCharge(
        Subscription $subscription,
        CancellationDecision $decision,
        ?string $actorUserId,
    ): string {
        $months = $decision->chargeableMonths ?? 0;

        if ($months <= 0) {
            // Nothing outstanding is not a €0 invoice: it is no invoice.
            // Numbering is gapless, so a document raised for nothing is one
            // that can never be removed.
            throw new ConflictException(
                'NOTHING_TO_CHARGE',
                'This early termination has nothing outstanding to charge.',
                $decision->toArray(),
            );
        }

        $version = $subscription->offer->version;
        $periods = $version->periodsIn($months);

        if ($periods === null || $periods <= 0) {
            // A CUSTOM period has no price to multiply. The catalogue already
            // refuses to sell a buy-out on such terms — the constraint is on
            // offer_versions — so reaching here means a version predating it
            // or one written around it, and either way the honest answer is
            // that this cannot be priced rather than a number nobody quoted.
            throw new ConflictException(
                'EARLY_TERMINATION_NOT_PRICEABLE',
                'These terms have no billing period, so an early termination cannot be priced.',
                ['billing_period' => $version->billingPeriod, 'chargeable_months' => $months],
            );
        }

        // **Whoever sold the thing bills for ending it** (2026-09-25). Until
        // today this always raised *product supplier → organisation*, even
        // for a seat — the platform charging a company for ending a contract
        // the company had sold to one of its own staff, on a document naming
        // nobody who was party to it. The same decision as the sale's, from
        // the same place, so the two cannot come apart again.
        $parties = $this->parties->forSale(
            $subscription->tenantId,
            $subscription->productId,
            $subscription->subscriber,
        );

        // One moment for the whole charge. Reading the clock twice could land
        // the fiscal fact on the far side of a rate window from the line it
        // is supposed to describe.
        $now = new DateTimeImmutable();

        $calculation = $this->taxation->calculate(
            $subscription->tenantId,
            $subscription->productId,
            $version->priceMinorUnits,
            $version->currency,
            null,
            $now,
        );

        $line = InvoiceLine::of(
            1,
            sprintf(
                '%s — early termination, %d month(s) of commitment remaining',
                $subscription->offer->name,
                $months,
            ),
            $periods,
            Money::of($version->priceMinorUnits, $version->currency),
            Money::zero($version->currency),
            $calculation->rateBasisPoints,
            $version->id,
        );

        // Derived from the line just built rather than recomputed from a
        // total: two lines of three cents at 20% charge two cents, and 20% of
        // six cents is one. The document is the fact.
        $facts = $this->taxation->factsFor(
            $subscription->tenantId,
            $subscription->productId,
            [$line],
            $now,
        );

        $supplyType = $this->taxation->defaultSupplyType($subscription->productId);

        $invoice = $this->invoices->applyIssue(
            $subscription->tenantId,
            $subscription->productId,
            $parties->issuerTenantId,
            $subscription->id,
            [$line],
            $parties->from,
            $parties->to,
            $parties->jurisdiction,
            $now,
            // The span being bought out: from today to the day the commitment
            // would have ended. That is what the customer is paying for, and
            // an invoice whose period says otherwise is a document that
            // cannot be explained to them.
            $subscription->commitmentEndsAt,
            self::PAYMENT_TERMS,
            $actorUserId,
            // Still inside the cancellation's transaction, so the release,
            // the invoice and its fiscal fact commit together or not at all.
            function (Invoice $issued) use ($subscription, $supplyType, $now, $facts): void {
                $this->taxation->recordFor(
                    $subscription->tenantId,
                    $subscription->productId,
                    $issued->id,
                    null,
                    $supplyType,
                    $now,
                    $facts,
                );
            },
        );

        return $invoice->id;
    }
}
