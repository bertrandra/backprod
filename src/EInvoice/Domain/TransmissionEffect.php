<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

/**
 * What a platform's verdict means for the invoice it concerned.
 *
 * A port, so the transmission repository never learns what an invoice is —
 * the same seam as {@see \App\Payment\Domain\PaymentSettlement}, and for the
 * same reason: the invoice must move inside the transaction recording the
 * verdict, or a rejected document could be observed as still awaiting one.
 */
interface TransmissionEffect
{
    /**
     * Called from inside the transaction recording the delivery, with the
     * invoice status the verdict implies.
     *
     * Implementations must tolerate an invoice that cannot make the move:
     * a verdict arriving for an invoice somebody cancelled in the meantime
     * is a real sequence, and refusing would turn it into a 500 the platform
     * then retries forever.
     */
    public function record(Transmission $transmission, string $invoiceStatus): void;
}
