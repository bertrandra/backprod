<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceParties;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\Money;
use App\Billing\Service\WhoSellsAndWhoBuys;
use App\Commerce\Domain\ChangeCharge;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscription;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * Moving up a plan is a sale, so it is invoiced like one (spec §3.2).
 *
 * **Through the normal billing chain, never a special path.** The same rule as
 * the early-termination charge: a document of its own, numbered in its
 * issuer's gapless series, dated and taxed. A charge that exists only as an
 * adjustment somewhere is a charge nobody can dispute, refund or declare.
 *
 * What it bills is **the new period in full**. The unconsumed part of the old
 * one is not deducted here — §3.3 decided it goes back to the payment method
 * instead — so this document says exactly one thing: the plan the customer is
 * now on, for the period that starts today. A negative line for the credit
 * would be the shape that decision rejected, and it would also make the
 * document's period describe two periods at once.
 *
 * **One line, two callers.** {@see quote} and {@see applyCharge} build the same
 * line from the same inputs, so the figure the preview shows cannot differ from
 * the figure the invoice carries. That is the property §25.3 gets by making
 * `/tax/calculate` share its code path with invoicing, stated here as a private
 * method rather than hoped for.
 *
 * **The rate is decided now, and the customer is who they are now** — the same
 * reasoning as {@see ChargeOnEarlyTermination}: this is a supply happening
 * today, so §25.3 applies to it today, and a customer whose VAT number has
 * since been verified moves up under reverse charge. And between the parties
 * {@see WhoSellsAndWhoBuys} resolves, never re-resolved downstream (ADR-057):
 * whoever sold the seat bills for changing it.
 */
final class ChargeOnOfferChange implements ChangeCharge
{
    private const PAYMENT_TERMS = 'Payable on receipt.';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WhoSellsAndWhoBuys $parties,
        private readonly Taxation $taxation,
    ) {
    }

    public function quote(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $at,
    ): int {
        if ($offer->version->priceMinorUnits === 0) {
            // Nothing to charge needs nobody to charge it to. Tax on nothing is
            // nothing, so resolving the parties would only be a way of refusing
            // a move that raises no document — and the plan a customer moves
            // onto for free is exactly the one they may hold before they have
            // ever given the platform an address.
            return 0;
        }

        $parties = $this->parties->forSale(
            $subscription->tenantId,
            $subscription->productId,
            $subscription->subscriber,
        );

        return self::lineFor($parties, $offer, $at, $this->taxation)->gross->minorUnits;
    }

    public function applyCharge(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): string {
        // One moment for the whole document. Reading the clock twice could
        // land the fiscal fact on the far side of a rate window from the line
        // it describes.
        $now = $periodStart;

        $parties = $this->parties->forSale(
            $subscription->tenantId,
            $subscription->productId,
            $subscription->subscriber,
        );

        $line = self::lineFor($parties, $offer, $now, $this->taxation);

        // Derived from the line just built rather than recomputed from a
        // total, so the facts sum to the document by construction.
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
            $now,
            // The period this document bills: the one the change opens. An
            // invoice whose period said otherwise would be a document nobody
            // can explain to the customer.
            $periodEnd,
            self::PAYMENT_TERMS,
            $actorUserId,
            // Still inside the transaction the change opened, so the move, the
            // invoice and its fiscal fact commit together or not at all.
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
            },
        );

        return $invoice->id;
    }

    /**
     * The one line both halves are built from.
     *
     * A single period at the arriving version's price — never a number of
     * periods, because a change of plan buys the period it opens and nothing
     * beyond it. And the description names the offer and its version, never
     * its plan's code: §13 forbids behaviour keyed on a plan, and a line
     * reading "Pro plan" is where a report would start grepping for one.
     */
    private static function lineFor(
        InvoiceParties $parties,
        SubscribedOffer $offer,
        DateTimeImmutable $at,
        Taxation $taxation,
    ): InvoiceLine {
        $version = $offer->version;

        $calculation = $taxation->calculateSale(
            $parties->sale,
            $version->priceMinorUnits,
            $version->currency,
            null,
            $at,
        );

        return InvoiceLine::of(
            1,
            sprintf('%s (v%d) — new period from a change of plan', $offer->name, $version->version),
            1,
            Money::of($version->priceMinorUnits, $version->currency),
            Money::zero($version->currency),
            $calculation->rateBasisPoints,
            $version->id,
        );
    }
}
