<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * What an invoice reaching PAID means for the sale it settles.
 *
 * The trigger is deliberately the *invoice*, not the payment. §25 lets an
 * invoice reach PAID two ways — a provider's webhook confirming a card, and
 * an operator reconciling a bank transfer by hand — and a gate hung off only
 * the first would leave every transfer-paying customer with an invoice marked
 * paid and nothing switched on. Both paths fire this, so the rule stays one
 * sentence: what was bought starts when the invoice for it is paid, however
 * it was paid.
 *
 * A port for the same reason {@see \App\Payment\Domain\PaymentSettlement} is
 * one: Billing must not learn what an order or a subscription is. It knows
 * only that something happens alongside its own write, inside its
 * transaction, and that whoever supplied this knows what.
 */
interface InvoicePaid
{
    /**
     * Called from inside the transaction that marked the invoice paid, and
     * only when that transition actually happened.
     *
     * Implementations must be safe for an invoice no sale is waiting on —
     * a subscription invoiced directly, an invoice raised by hand — which is
     * the ordinary case rather than the exception.
     */
    public function paid(Invoice $invoice): void;
}
