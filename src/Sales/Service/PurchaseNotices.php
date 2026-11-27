<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoicePaymentFailed;
use App\Notification\Domain\Category;
use App\Notification\Service\Notifications;
use App\Product\Domain\ProductRepository;
use App\Sales\Domain\Order;
use App\Sales\Domain\SalesRepository;
use App\Tenant\Domain\DefaultTenant;
use App\Tenant\Domain\TenantRepository;

/**
 * What a person is told about a purchase of theirs (ADR-079).
 *
 * Until 2026-11-27 a purchase was silent from end to end: nothing said the
 * seat had started, nothing said the card was refused, and nothing said the
 * money taken for a second seat had gone back. The only notices this platform
 * raised about money were the ones that ask for it.
 *
 * Three facts, three notices, each raised **on the transaction of the fact**
 * (§27.1) and sent by the queue, never inside the request or the webhook that
 * recorded it:
 *
 *   - the purchase is confirmed — the order completed and the seat started;
 *   - the payment failed — the attempt is dead and the purchase is waiting;
 *   - the payment was given back — it arrived for a seat the person already
 *     held, so nothing could be started against it.
 *
 * **Addressed to the person the order is for.** A seat is bought by somebody
 * for themselves (ADR-055), so there is one person to tell and no
 * administrators to copy: telling a company about a colleague's own purchase
 * is the disclosure the collection pass already declines to make.
 *
 * **Not kept as sent.** None of these is a demand or a notice with legal
 * effect — the invoice and the credit note are the documents — so the payload
 * is stored and the words are rendered at send time, like any other notice.
 * The payload carries what the words need and nothing else: no card, no
 * provider message, no amount (§31).
 *
 * Once each, by the notifications' dedup index rather than a check: a webhook
 * delivered twice, or a pass that ran twice, raises one.
 */
final class PurchaseNotices implements InvoicePaymentFailed
{
    public const CONFIRMED = 'purchase.confirmed';
    public const PAYMENT_FAILED = 'purchase.payment_failed';
    public const REFUNDED = 'purchase.refunded';

    public function __construct(
        private readonly Notifications $notifications,
        private readonly SalesRepository $sales,
        private readonly TenantRepository $tenants,
        private readonly DefaultTenant $defaultTenant,
        private readonly ProductRepository $products,
        private readonly string $appUrl = '',
    ) {
    }

    /** The order completed and what it bought has started. */
    public function confirmed(Order $order, string $offerName): void
    {
        $this->notifications->raise(
            $order->tenantId,
            $order->productId,
            $order->subscriberUserId,
            self::CONFIRMED,
            Category::BILLING,
            [
                'order_id' => $order->id,
                'offer' => $offerName,
                'link' => $this->linkTo($order, '/subscription'),
            ],
            'purchase-confirmed:' . $order->id,
        );
    }

    /**
     * An attempt at an invoice failed. Only a purchase in flight is told: an
     * invoice with no order waiting on it is a renewal or a debt, and the
     * collection schedule already speaks for those in its own words.
     */
    public function failed(Invoice $invoice, string $paymentId): void
    {
        $order = $this->sales->findOrderAwaitingPayment($invoice->tenantId, $invoice->productId, $invoice->id);

        if ($order === null) {
            return;
        }

        $this->notifications->raise(
            $order->tenantId,
            $order->productId,
            $order->subscriberUserId,
            self::PAYMENT_FAILED,
            Category::BILLING,
            [
                'order_id' => $order->id,
                'offer' => self::describe($order),
                // Where the purchase waits, and where a new attempt is made.
                'link' => $this->linkTo($order, '/checkout/' . rawurlencode($order->id)),
            ],
            'purchase-payment-failed:' . $paymentId,
        );
    }

    /** The money arrived for a seat already held, and has been given back. */
    public function refunded(Order $order): void
    {
        $this->notifications->raise(
            $order->tenantId,
            $order->productId,
            $order->subscriberUserId,
            self::REFUNDED,
            Category::BILLING,
            [
                'order_id' => $order->id,
                'offer' => self::describe($order),
                'link' => $this->linkTo($order, '/subscription'),
            ],
            'purchase-refunded:' . $order->id,
        );
    }

    /** What was bought, in the words of the order's own line. */
    private static function describe(Order $order): string
    {
        return $order->lines[0]->description ?? '';
    }

    /**
     * A screen under the organisation's root and for the product the order
     * belongs to — the address a person signed in there would see.
     */
    private function linkTo(Order $order, string $path): string
    {
        $tenant = $this->tenants->find($order->tenantId);
        $root = $tenant === null || $this->defaultTenant->id() === $tenant->id ? '' : '/' . $tenant->slug;
        $product = $this->products->find($order->productId);
        $query = $product === null ? '' : '?product=' . rawurlencode($product->code);

        return rtrim($this->appUrl, '/') . $root . $path . $query;
    }
}
