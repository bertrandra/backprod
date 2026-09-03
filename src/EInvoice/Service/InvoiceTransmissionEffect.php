<?php

declare(strict_types=1);

namespace App\EInvoice\Service;

use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\EInvoice\Domain\Transmission;
use App\EInvoice\Domain\TransmissionEffect;

/**
 * A platform's verdict moves the invoice with it.
 *
 * Deliberately silent when the move is not legal. An invoice cancelled while
 * its transmission was in flight cannot become ACCEPTED, and that is a real
 * sequence rather than a fault: the transmission still records what the
 * platform said, and the invoice stays cancelled. Raising here would make the
 * platform retry a delivery that will never apply.
 */
final class InvoiceTransmissionEffect implements TransmissionEffect
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    public function record(Transmission $transmission, string $invoiceStatus): void
    {
        $invoice = $this->invoices->find(
            $transmission->tenantId,
            $transmission->productId,
            $transmission->invoiceId,
        );

        if ($invoice === null || !InvoiceStatus::permits($invoice->status, $invoiceStatus)) {
            return;
        }

        // The participating variant: the caller already holds the transaction
        // recording the verdict, and nesting would make correctness depend on
        // how the driver handles it.
        $this->invoices->applyTransition($invoice, $invoiceStatus, null);
    }
}
