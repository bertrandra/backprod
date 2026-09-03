<?php

declare(strict_types=1);

namespace App\Sales\Domain;

/**
 * What placing an order actually causes: a subscription and an invoice.
 *
 * A port rather than a direct call, for the same reason {@see \App\Payment\Domain\PaymentSettlement}
 * is one — the sales repository must not learn what a subscription or an
 * invoice is. It knows only that something has to happen inside its
 * transaction and that whoever supplied this knows what.
 *
 * The transaction is not optional here. `orders_completed_is_traceable`
 * refuses a completed order that does not name both, so a fulfilment that
 * half-succeeded would either fail the constraint or leave a subscription
 * nobody can trace back to the sale that started it.
 */
interface OrderFulfilment
{
    /**
     * Called from inside the transaction completing the order.
     *
     * @return array{subscription_id: string, invoice_id: string}
     */
    public function fulfil(Order $order): array;
}
