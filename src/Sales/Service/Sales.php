<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Billing\Service\WhoSellsAndWhoBuys;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Service\Catalogue;
use App\Sales\Domain\Order;
use App\Sales\Domain\OrderFulfilment;
use App\Sales\Domain\SalesRepository;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Service\Taxation;

/**
 * The sales half of §20's chain, as it now runs: Order → Invoice → Payment →
 * Seat.
 *
 * **The tenant surface sells seats and nothing else** (2026-09-25). A person
 * decides to use a product and buys one for themselves; the organisation
 * administers — its members, its billing identity, its VAT, its documents —
 * and reads what its people hold. So `Subscriber::tenant()` is not reachable
 * from here: `order()` has nowhere to express it, rather than a button
 * somewhere not offering it.
 *
 * Two things went with that, and both were working:
 *
 * - **The quote.** A quote priced an offer for the *organisation* and
 *   accepting one placed the organisation's order, so it was the same sale
 *   with a document in front of it. `Quote` itself stays — the rows a
 *   deployment already has are still readable, from the console and by the
 *   job that lapses them — but nothing on the tenant surface raises,
 *   accepts or rejects one.
 * - **The refusal in front of it.** `SUBSCRIPTION_ALREADY_ACTIVE` guarded a
 *   second organisation subscription, reached before any document existed.
 *   With no way to buy the first, it guarded nothing; the partial unique
 *   index it stood in front of is still in the schema, where the rule
 *   belongs.
 *
 * What survives unchanged is the reason the chain has the shape it does: an
 * order is not a subscription. Fulfilling raises the invoice; the seat starts
 * when that invoice is paid, because an order fulfilled the moment it is
 * placed extends credit to everyone who can reach the endpoint.
 */
final class Sales
{
    public const SEAT_ALREADY_ACTIVE = 'SEAT_ALREADY_ACTIVE';

    /**
     * No longer thrown, and still recorded (2026-09-25).
     *
     * Nothing on the tenant surface can place an organisation's order any
     * more, so there is no purchase for this to refuse. But an order placed
     * before today may still be awaiting its payment, and when that payment
     * lands {@see CompleteOrderOnPayment} has to say why it is not starting
     * a second subscription. That reason is written onto the held order, so
     * the word has to exist somewhere — here, beside the one it pairs with,
     * rather than invented as a literal at the place that records it.
     */
    public const SUBSCRIPTION_ALREADY_ACTIVE = 'SUBSCRIPTION_ALREADY_ACTIVE';

    public function __construct(
        private readonly SalesRepository $sales,
        private readonly Catalogue $catalogue,
        private readonly Taxation $taxation,
        private readonly OrderFulfilment $fulfilment,
        private readonly SubscriptionRepository $subscriptions,
        private readonly WhoSellsAndWhoBuys $parties,
    ) {
    }

    /**
     * @return array{orders: list<Order>, total: int, limit: int, offset: int}
     */
    public function orders(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null): array
    {
        return [
            'orders' => $this->sales->listOrders($tenantId, $productId, $limit, $offset, $ownedBy),
            'total' => $this->sales->countOrders($tenantId, $productId, $ownedBy),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function showOrder(string $tenantId, string $productId, string $orderId, ?string $ownedBy = null): Order
    {
        $order = $this->sales->findOrder($tenantId, $productId, $orderId, $ownedBy);

        if ($order === null) {
            throw new NotFoundException('Order not found.', [], 'ORDER_NOT_FOUND');
        }

        return $order;
    }

    /**
     * Buys an offer for the person asking — a **seat**, and nothing else
     * (2026-09-25).
     *
     * This took a `$seat` flag until today, and `false` bought the
     * organisation's own subscription. Both are gone, because the operator's
     * model is that a person decides to use a product and an organisation
     * administers: the tenant surface sells seats, and `Subscriber::tenant()`
     * is not reachable from it at all. Not a hidden button — a signature with
     * nowhere to express the other sale, which is the only way "we sell seats"
     * is a fact rather than a habit.
     *
     * The platform keeps the concept: `subscriber_kind = TENANT` is still a
     * column, still carries the rows a deployment already has, and is still
     * what `Subscriptions::subscribe` writes when something else calls it.
     * What no longer exists is a way for a customer to buy one.
     *
     * @throws ConflictException SEAT_NEEDS_A_PERSON | SEAT_ALREADY_ACTIVE
     */
    public function order(string $tenantId, string $productId, string $offerId, ?string $actorUserId): Order
    {
        $offer = SubscribedOffer::from($this->catalogue->offerOnSale($productId, $offerId));

        if ($actorUserId === null) {
            throw new ConflictException('SEAT_NEEDS_A_PERSON', 'A seat is taken out by the person it is for.');
        }

        $this->refuseWhileSeated($tenantId, $productId, $actorUserId);

        $subscriber = Subscriber::user($actorUserId);

        // Priced under the regime the invoice will be issued under
        // (2026-09-26): the organisation selling a seat to one of its own
        // people, not the platform selling to the organisation. The two
        // disagreeing is not a rounding difference — it is `TAX_TERMS_CHANGED`
        // at fulfilment, an order nobody can pay, raised the moment a tenant
        // and the platform are in different countries.
        //
        // It also moves the refusals forward. An organisation with no billing
        // profile, or one that has not said which country it sells from, is
        // told so before an order exists rather than when its invoice is
        // raised — and an order is cheaper to not create than a document is
        // to not issue.
        $parties = $this->parties->forSale($tenantId, $productId, $subscriber);

        $line = InvoiceLine::of(
            1,
            $offer->lineDescription(),
            1,
            Money::of($offer->version->priceMinorUnits, $offer->version->currency),
            Money::zero($offer->version->currency),
            $this->taxation->calculateSale(
                $parties->sale,
                $offer->version->priceMinorUnits,
                $offer->version->currency,
                null,
                null,
            )->rateBasisPoints,
            $offer->version->id,
        );

        return $this->sales->placeOrder(
            $tenantId,
            $productId,
            null,
            $offer->version->id,
            [$line],
            $actorUserId,
            $subscriber,
        );
    }

    /**
     * One live seat per person per product, which is one of the two partial
     * unique indexes (§13.1) — the other, one subscription per organisation,
     * stands in front of nothing a customer can now reach.
     *
     * @throws ConflictException SEAT_ALREADY_ACTIVE
     */
    private function refuseWhileSeated(string $tenantId, string $productId, string $userId): void
    {
        foreach ($this->subscriptions->liveFor($tenantId, $productId, $userId) as $live) {
            if ($live->subscriber->isSeat() && $live->status === 'ACTIVE') {
                throw new ConflictException(
                    self::SEAT_ALREADY_ACTIVE,
                    'You already hold a live seat on this product. Change it from the subscription rather than buying a second one.',
                    ['subscription_id' => $live->id, 'offer_id' => $live->offer->offerId],
                );
            }
        }
    }

    /**
     * Raises the invoice a placed order is to be paid against.
     *
     * What it deliberately does not do is start the subscription. That waits
     * for the money: an order fulfilled the moment it is placed extends
     * credit to everyone who can reach this endpoint, which is a decision
     * worth taking on purpose rather than by default. The subscription starts
     * when the invoice is paid, through {@see \App\Billing\Domain\InvoicePaid}.
     *
     * An order with nothing to collect completes here, in the same
     * transaction — there is no payment to wait for.
     */
    public function fulfil(string $tenantId, string $productId, string $orderId, ?string $actorUserId): Order
    {
        $order = $this->showOrder($tenantId, $productId, $orderId);

        // Only PENDING. An order already awaiting payment has an invoice with
        // a legal number on it, and numbering is gapless: raising a second
        // one because somebody pressed the button twice is a document that
        // cannot be deleted afterwards.
        if ($order->status !== Order::PENDING) {
            throw new ConflictException(
                'ORDER_NOT_FULFILLABLE',
                'Only a pending order can be fulfilled.',
                ['status' => $order->status],
            );
        }

        return $this->sales->fulfilOrder($order, $this->fulfilment, $actorUserId);
    }

    public function cancelOrder(string $tenantId, string $productId, string $orderId, ?string $actorUserId): Order
    {
        $order = $this->showOrder($tenantId, $productId, $orderId);

        if ($order->invoiceId !== null) {
            // Once an invoice exists the order is no longer the document that
            // matters. Undoing it is a credit note, which is a deliberate act
            // with its own number — and that holds whether the order was
            // completed or is still waiting to be paid, because the invoice
            // is issued either way.
            throw new ConflictException(
                'ORDER_NOT_CANCELLABLE',
                'An invoiced order is undone by crediting its invoice, not by cancelling it.',
                ['status' => $order->status, 'invoice_id' => $order->invoiceId],
            );
        }

        return $this->sales->cancelOrder($order, $actorUserId);
    }

    /**
     * Abandons an order that is still waiting for its money (2026-09-18).
     *
     * The one case {@see cancelOrder} refuses — an invoice exists — and the
     * one case where refusing is wrong: the buyer closed the card form, no
     * money moved, and the invoice is a debt nobody intends to settle. The
     * checkout, which raised that invoice, cancels it *first* (ISSUED →
     * CANCELLED, its number kept, as gapless numbering requires) and then
     * calls this; an order whose invoice is still live is refused here so
     * that the two cannot come apart.
     */
    public function abandonOrder(Order $order, ?string $actorUserId): Order
    {
        if ($order->status !== Order::AWAITING_PAYMENT) {
            throw new ConflictException(
                'ORDER_NOT_CANCELLABLE',
                'Only an order still waiting for its payment can be abandoned.',
                ['status' => $order->status],
            );
        }

        return $this->sales->cancelOrder($order, $actorUserId);
    }
}
