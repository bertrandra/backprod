<?php

declare(strict_types=1);

namespace App\Checkout\Service;

use App\Billing\Domain\InvoiceStatus;
use App\Billing\Service\Invoicing;
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
        private readonly Invoicing $invoicing,
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
     * @return array{order: Order, payment: Payment|null, client_secret: string|null, provider: array{name: string, sandbox: bool, client_key: string|null}|null}
     */
    public function open(string $tenantId, string $productId, string $offerId, ?string $actorUserId, bool $seat = false): array
    {
        $order = $this->sales->order($tenantId, $productId, $offerId, $actorUserId, $seat);
        $order = $this->sales->fulfil($tenantId, $productId, $order->id, $actorUserId);

        if ($order->invoiceId === null) {
            // An order with nothing to collect completes at fulfilment — a
            // free offer, or one entirely covered by credit. There is no
            // payment to start and nothing for the caller to pay.
            return ['order' => $order, 'payment' => null, 'client_secret' => null, 'provider' => null];
        }

        $started = $this->payments->start($tenantId, $productId, $order->invoiceId, $actorUserId);

        return [
            'order' => $order,
            'payment' => $started['payment'],
            'client_secret' => $started['client_secret'],
            'provider' => $started['provider'],
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
    public function show(string $tenantId, string $productId, string $sessionId, ?string $ownedBy = null): Order
    {
        return $this->sales->showOrder($tenantId, $productId, $sessionId, $ownedBy);
    }

    /**
     * Giving up on a purchase before it is paid (2026-09-18).
     *
     * The buyer closed the card form, or thought better of it: the order is
     * waiting for money that is not coming, and the invoice it raised is a
     * debt nobody intends to settle. Both are cancelled, invoice first — its
     * number stays, as a cancelled document, because numbering is gapless
     * (§26) — and the order after, so a failure between the two leaves an
     * order that still says what happened to its invoice.
     *
     * Refused once money has moved: a settled attempt is a sale to release
     * or a payment to refund, never a purchase to forget. And refused for a
     * completed or already cancelled order, where there is nothing to give
     * up.
     */
    public function cancel(string $tenantId, string $productId, string $sessionId, ?string $actorUserId, ?string $ownedBy = null): Order
    {
        $order = $this->sales->showOrder($tenantId, $productId, $sessionId, $ownedBy);

        if ($order->status !== Order::AWAITING_PAYMENT) {
            throw new ConflictException(
                'CHECKOUT_NOT_CANCELLABLE',
                'Only a checkout still waiting for its payment can be cancelled.',
                ['status' => $order->status],
            );
        }

        if ($order->invoiceId !== null) {
            $latest = $this->payments->latestFor($tenantId, $productId, $order->invoiceId);

            if ($latest !== null && $latest->isSettled()) {
                throw new ConflictException(
                    'CHECKOUT_ALREADY_PAID',
                    'The payment has arrived; this purchase can no longer be cancelled.',
                    ['payment_id' => $latest->id, 'payment_status' => $latest->status],
                );
            }

            $invoice = $this->invoicing->show($tenantId, $productId, $order->invoiceId);

            if ($invoice->status !== InvoiceStatus::CANCELLED) {
                $this->invoicing->cancel($tenantId, $productId, $order->invoiceId, $actorUserId);
            }
        }

        return $this->sales->abandonOrder($order, $actorUserId);
    }

    /**
     * A fresh attempt at an invoice a previous payment failed to collect.
     *
     * Never a resurrection of the old payment. `PaymentStatus` is one-way and
     * says why: the customer may have used a different instrument, and two
     * attempts that must be told apart cannot share a provider reference.
     *
     * `client_secret` is nullable for the same reason it is on a first
     * attempt: a redirect-based provider issues no secret, and promising one
     * here would be promising something the port does not guarantee.
     *
     * @return array{payment: Payment, client_secret: string|null, provider: array{name: string, sandbox: bool, client_key: string|null}}
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
