<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Shared\Exceptions\ConflictException;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * Cancels an invoice nobody has paid, and withdraws what it declared.
 *
 * The body of {@see Invoicing::cancel()}, moved here on 2026-11-27 (ADR-076) so
 * that something other than `Invoicing` can do it. A change of plan withdraws a
 * renewal invoice raised ahead for the plan being left, and it cannot ask
 * `Invoicing` to: `Invoicing` depends on `Subscriptions` — it invoices the
 * subscription held — and `Subscriptions` is what is changing the plan. One
 * rule, in one place, reachable from both sides of that.
 *
 * A draft was never sent and declared nothing: it is simply cancelled. An
 * issued invoice declared its VAT when it was issued, so cancelling it writes
 * the reversal of its facts, dated today, in the same transaction as the move:
 * the number stays, as a cancelled document (the sequence has no gaps), and the
 * return nets to nothing. The correction lands in the period it is made in,
 * never back in the one the sale was declared in, which may be closed.
 *
 * An invoice already credited in part is refused: reversing all of it would
 * reverse the credited part twice.
 */
final class InvoiceCancellation
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly Taxation $taxation,
    ) {
    }

    public function cancel(Invoice $invoice, ?string $actorUserId): Invoice
    {
        InvoiceStatus::assertPermits($invoice->status, InvoiceStatus::CANCELLED);

        if (!InvoiceStatus::isFinal($invoice->status)) {
            return $this->invoices->transition($invoice, InvoiceStatus::CANCELLED, $actorUserId);
        }

        if ($this->creditNotes->creditedOn($invoice->id) > 0) {
            throw new ConflictException(
                'INVOICE_PARTLY_CREDITED',
                'Part of this invoice has been credited already. Credit the rest instead of cancelling it.',
            );
        }

        // Read before anything is written, as a credit note reads its own.
        $reversal = Taxation::reversalOf(
            $this->taxation->factsOfInvoice($invoice->id),
            'Reversal of the invoice this cancellation withdraws.',
        );
        $withdrawnAt = new DateTimeImmutable();

        return $this->invoices->transition(
            $invoice,
            InvoiceStatus::CANCELLED,
            $actorUserId,
            function (Invoice $cancelled) use ($reversal, $withdrawnAt): void {
                foreach ($reversal as $supplyType => $calculations) {
                    $this->taxation->recordFor(
                        $cancelled->tenantId,
                        $cancelled->productId,
                        // The return the sale was filed in (ADR-054).
                        $cancelled->issuerTenantId,
                        $cancelled->id,
                        null,
                        $supplyType,
                        $withdrawnAt,
                        $calculations,
                    );
                }
            },
        );
    }
}
