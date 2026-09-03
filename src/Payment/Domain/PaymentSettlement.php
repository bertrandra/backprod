<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What a successful payment means for the document it paid.
 *
 * This exists so the invoice moves to PAID inside the *same* transaction
 * that records the webhook delivery. Doing it afterwards would leave a
 * window in which a payment is collected and the invoice it settled still
 * says it is owed — and a crash in that window leaves it that way
 * permanently, which is a customer being chased for money they have paid.
 *
 * It is a port rather than a direct call so the payment repository never
 * learns what an invoice is: it knows only that something has to happen
 * alongside its own write, and that whoever supplied this knows what.
 */
interface PaymentSettlement
{
    /**
     * Called only for a payment that has just succeeded, and only from
     * inside the transaction recording that fact.
     *
     * Implementations must be safe to call for an invoice that is already
     * settled: the same payment can succeed only once, but the invoice may
     * have been marked paid by hand in the meantime.
     */
    public function settle(Payment $payment): void;
}
