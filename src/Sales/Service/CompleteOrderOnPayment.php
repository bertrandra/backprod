<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoicePaid;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionRepository;
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
 *
 * **An order that cannot be released is held, not failed.** The sale is
 * refused at placement while the tenant's subscription to the product is
 * live ({@see Sales}); what reaches here regardless is the race — two
 * orders opened before either was paid, the second paid after the first
 * started the subscription. The money has arrived by then, and a thrown
 * unique violation would roll the whole delivery back: payment PENDING,
 * invoice ISSUED, provider retrying a delivery that can never succeed, and
 * a customer charged for something nobody recorded. That is what the
 * operator's deployment showed on 2026-09-17. So the caller's transaction
 * keeps the money and the paid invoice, the order stays where it is, and a
 * ledger row says why — the operator refunds a customer rather than
 * discovers one.
 */
final class CompleteOrderOnPayment implements InvoicePaid
{
    public function __construct(
        private readonly SalesRepository $sales,
        private readonly OrderFulfilment $fulfilment,
        private readonly SubscriptionRepository $subscriptions,
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

        // Asked, not caught: a unique violation inside the caller's
        // transaction aborts it, and there is no continuing past one in
        // PostgreSQL. The index still stands behind this for two deliveries
        // landing in the same instant — that one is a 500 the provider
        // retries, and the retry reads the row.
        $live = $order->subscriber->isSeat()
            ? $this->liveSeat($invoice->tenantId, $invoice->productId, (string) $order->subscriber->userId)
            : $this->subscriptions->findActive($invoice->tenantId, $invoice->productId);

        if ($live !== null) {
            $this->sales->applyHoldOrder(
                $order,
                [
                    'reason' => $order->subscriber->isSeat() ? Sales::SEAT_ALREADY_ACTIVE : Sales::SUBSCRIPTION_ALREADY_ACTIVE,
                    'subscription_id' => $live->id,
                ],
                null,
            );

            return;
        }

        // The participating variant: the caller holds the transaction that
        // marked the invoice paid, and the subscription starting is part of
        // the same fact.
        $this->sales->applyCompleteOrder($order, $this->fulfilment, null);
    }

    /** The person's own live seat on the product, if they hold one (§13.1). */
    private function liveSeat(string $tenantId, string $productId, string $userId): ?Subscription
    {
        foreach ($this->subscriptions->liveFor($tenantId, $productId, $userId) as $live) {
            if ($live->subscriber->isSeat() && $live->status === 'ACTIVE') {
                return $live;
            }
        }

        return null;
    }
}
