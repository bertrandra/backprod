<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentSettlement;

/**
 * A successful payment settles the invoice it was raised against.
 *
 * Deliberately tolerant of an invoice that is already PAID: somebody may
 * have reconciled a bank transfer by hand while the card payment was in
 * flight, and refusing here would turn a harmless race into a 500 that the
 * provider then retries forever.
 *
 * Equally deliberately, it papers over nothing else — a CANCELLED invoice
 * that receives a payment is left alone and the payment still records as
 * succeeded, because the money genuinely arrived and pretending otherwise
 * would lose it. What that leaves is a payment against a void document,
 * which is exactly the anomaly an operator should see rather than one this
 * code should hide.
 */
final class InvoiceSettlement implements PaymentSettlement
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    public function settle(Payment $payment): void
    {
        $invoice = $this->invoices->find($payment->tenantId, $payment->productId, $payment->invoiceId);

        if ($invoice === null || !InvoiceStatus::permits($invoice->status, InvoiceStatus::PAID)) {
            return;
        }

        // The participating variant: the caller already holds the
        // transaction that is recording the payment, and opening a second
        // one inside it would make correctness depend on how the driver
        // nests.
        $this->invoices->applyTransition($invoice, InvoiceStatus::PAID, null);
    }
}
