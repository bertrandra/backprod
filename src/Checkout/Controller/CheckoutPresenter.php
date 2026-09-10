<?php

declare(strict_types=1);

namespace App\Checkout\Controller;

use App\Payment\Domain\Payment;
use App\Sales\Domain\Order;

/**
 * A checkout session on the wire.
 *
 * The `id` is the order's, because the session **is** the order. Saying so in
 * the field name would tie every client to that fact; leaving it as `id` lets
 * a future session become something else without a rename, while
 * `order_id` beside it stays honest about what it is today.
 */
final class CheckoutPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function session(Order $order, ?Payment $payment): array
    {
        return [
            'id' => $order->id,
            'order_id' => $order->id,
            'status' => self::status($order, $payment),
            'invoice_id' => $order->invoiceId,
            'subscription_id' => $order->subscriptionId,
            'payment_id' => $payment?->id,
            'payment_status' => $payment?->status,
            'net' => self::money($order->net->minorUnits, $order->net->currency),
            'vat' => self::money($order->vat->minorUnits, $order->vat->currency),
            'gross' => self::money($order->gross->minorUnits, $order->gross->currency),
        ];
    }

    /**
     * Derived, never stored.
     *
     * The order's own status is the authority on whether this completed, and
     * the payment's on whether money moved. Writing a third status down
     * somewhere would be a third answer that could disagree with both.
     */
    private static function status(Order $order, ?Payment $payment): string
    {
        if ($order->status === Order::COMPLETED) {
            return 'COMPLETED';
        }

        if ($order->status === Order::CANCELLED) {
            return 'CANCELLED';
        }

        if ($payment !== null && $payment->isFinal() && !$payment->isSettled()) {
            // The order is still awaiting payment and the last attempt is
            // dead, which is the state a retry exists for. Reporting it as
            // "awaiting payment" would hide that nothing is coming.
            return 'PAYMENT_FAILED';
        }

        return 'AWAITING_PAYMENT';
    }

    /**
     * @return array{minor_units: int, currency: string}
     */
    private static function money(int $minorUnits, string $currency): array
    {
        return ['minor_units' => $minorUnits, 'currency' => $currency];
    }
}
