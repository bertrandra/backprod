<?php

declare(strict_types=1);

namespace App\Checkout\Service;

use App\Payment\Domain\Payment;
use App\Payment\Service\Payments;
use App\Sales\Domain\Order;
use App\Sales\Service\Sales;
use App\Shared\Exceptions\ConflictException;

/**
 * Buying something in one call (§7's Checkout block).
 *
 * **A checkout session is an order.** There is no `checkout_sessions` table
 * and no session id: the id returned here is the order's, and everything a
 * session would have held — is it paid, what was invoiced, did the
 * subscription start — is already on the order, the invoice and the payment.
 * A second row tracking the same lifecycle is a second answer that can
 * disagree with the first, and the first is the one the money is attached to.
 *
 * So this composes what already exists rather than adding a path beside it:
 * place the order, raise its invoice, ask the provider to authorize. Every
 * rule in that chain still applies — the offer must be on sale, the tenant
 * must have a billing profile, the invoice is numbered gaplessly — and none
 * of them is re-implemented here.
 *
 * **It does not start the subscription.** That waits for the money, through
 * the same webhook everything else arrives by (M6.3). A checkout that
 * activated on creation would extend credit to anyone who can reach the
 * endpoint.
 */
final class Checkout
{
    public function __construct(
        private readonly Sales $sales,
        private readonly Payments $payments,
    ) {
    }

    /**
     * Order, invoice and authorize, in that order.
     *
     * Not one transaction, and it cannot be: the middle step talks to a
     * payment provider over the network. What makes that safe is that each
     * step leaves a durable, addressable record — a caller whose connection
     * drops after the invoice is raised has an order they can look up and
     * retry the payment on, rather than a charge nobody can account for.
     *
     * @return array{order: Order, payment: Payment|null, client_secret: string|null}
     */
    public function open(string $tenantId, string $productId, string $offerId, ?string $actorUserId): array
    {
        $order = $this->sales->order($tenantId, $productId, $offerId, $actorUserId);
        $order = $this->sales->fulfil($tenantId, $productId, $order->id, $actorUserId);

        if ($order->invoiceId === null) {
            // An order with nothing to collect completes at fulfilment — a
            // free offer, or one entirely covered by credit. There is no
            // payment to start and nothing for the caller to pay.
            return ['order' => $order, 'payment' => null, 'client_secret' => null];
        }

        $started = $this->payments->start($tenantId, $productId, $order->invoiceId, $actorUserId);

        return [
            'order' => $order,
            'payment' => $started['payment'],
            'client_secret' => $started['client_secret'],
        ];
    }

    /**
     * The session as it now stands.
     *
     * No client secret. It is short-lived and it is a credential of sorts, so
     * it is returned once when it is issued and never stored (§31) — there is
     * nothing here to return it from. A caller who needs a fresh one retries
     * the payment, which is a new attempt and gets its own.
     */
    public function show(string $tenantId, string $productId, string $sessionId): Order
    {
        return $this->sales->showOrder($tenantId, $productId, $sessionId);
    }

    /**
     * A fresh attempt at an invoice a previous payment failed to collect.
     *
     * Never a resurrection of the old payment. `PaymentStatus` is one-way and
     * says why: the customer may have used a different instrument, and two
     * attempts that must be told apart cannot share a provider reference.
     *
     * @return array{payment: Payment, client_secret: string}
     */
    public function retry(string $tenantId, string $productId, string $paymentId, ?string $actorUserId): array
    {
        $previous = $this->payments->show($tenantId, $productId, $paymentId);

        if (!$previous->isFinal()) {
            // Still in flight. Starting a second authorization now risks
            // collecting twice for one invoice, and the provider may yet
            // settle this one.
            throw new ConflictException(
                'PAYMENT_STILL_IN_FLIGHT',
                'That payment has not finished. Wait for it to settle or fail before retrying.',
                ['status' => $previous->status],
            );
        }

        if ($previous->isSettled()) {
            throw new ConflictException(
                'PAYMENT_ALREADY_SETTLED',
                'That payment succeeded. There is nothing to retry.',
                ['status' => $previous->status],
            );
        }

        // `Payments::start` refuses an invoice that is not ISSUED, so an
        // invoice settled by some other means is refused there rather than
        // re-checked here.
        return $this->payments->start($tenantId, $productId, $previous->invoiceId, $actorUserId);
    }
}
