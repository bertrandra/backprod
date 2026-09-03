<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoicePaid;
use App\Sales\Domain\OrderFulfilment;
use App\Sales\Domain\SalesRepository;

/**
 * An invoice reaching PAID releases the sale that was waiting on it.
 *
 * This is the far side of the gate. The order was parked at AWAITING_PAYMENT
 * when its invoice was raised; the money has now arrived — by card through
 * the provider's webhook, or by transfer through an operator marking it paid
 * — and the subscription starts here, inside whichever transaction recorded
 * that fact.
 *
 * Finding nothing is the ordinary case, not a failure. Most invoices have no
 * order behind them at all: a subscription billed for its next period, a
 * document raised by hand. And an order already completed is not found either,
 * because the lookup asks for one still awaiting payment — which is what
 * makes a second delivery of the same event harmless here, on top of the
 * unique index that stops it reaching this code twice.
 */
final class CompleteOrderOnPayment implements InvoicePaid
{
    public function __construct(
        private readonly SalesRepository $sales,
        private readonly OrderFulfilment $fulfilment,
    ) {
    }

    public function paid(Invoice $invoice): void
    {
        $order = $this->sales->findOrderAwaitingPayment(
            $invoice->tenantId,
            $invoice->productId,
            $invoice->id,
        );

        if ($order === null) {
            return;
        }

        // The participating variant: the caller holds the transaction that
        // marked the invoice paid, and the subscription starting is part of
        // the same fact.
        $this->sales->applyCompleteOrder($order, $this->fulfilment, null);
    }
}
