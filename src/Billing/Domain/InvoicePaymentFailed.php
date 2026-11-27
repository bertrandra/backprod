<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * What a failed attempt at an invoice means for whoever was waiting on it.
 *
 * The other half of {@see InvoicePaid}, and a port for the same reason: the
 * payment layer knows an attempt failed and the invoice it was for, and must
 * not learn what a purchase is or who to tell. Whoever supplies this knows.
 *
 * Called from inside the transaction that records the failure, so the fact
 * and the notice of it commit together (§27.1) — and so implementations use
 * the participating variants of whatever they write through.
 */
interface InvoicePaymentFailed
{
    /**
     * @param string $paymentId the attempt that failed — what makes two
     *                          failures of one invoice two notices and one
     *                          failure delivered twice a single one
     */
    public function failed(Invoice $invoice, string $paymentId): void;
}
