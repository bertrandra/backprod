<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Service\Catalogue;
use App\Sales\Domain\Order;
use App\Sales\Domain\OrderFulfilment;
use App\Sales\Domain\Quote;
use App\Sales\Domain\QuoteStatus;
use App\Sales\Domain\SalesRepository;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * The sales half of §20's chain: Quote → Order → Subscription/Purchase.
 *
 * The through-line from the rest of the platform holds here too. A quote
 * lapses on the clock — `isOpenAt(now())`, never the status column alone —
 * because a lapse is a fact about the clock, not about whether a sweep has
 * run. And what a quote priced is copied forward into the order and then the
 * invoice rather than re-derived, so a customer is billed what they were
 * quoted even if the offer moved in between.
 */
final class Sales
{
    /**
     * How long a quote stands by default. Configurable per request; this is
     * the number used when nobody chooses, and 30 days is the ordinary
     * commercial convention.
     */
    public const DEFAULT_VALIDITY_DAYS = 30;
    public const MAX_VALIDITY_DAYS = 365;

    public function __construct(
        private readonly SalesRepository $sales,
        private readonly Catalogue $catalogue,
        private readonly BillingProfileRepository $profiles,
        private readonly Taxation $taxation,
        private readonly OrderFulfilment $fulfilment,
    ) {
    }

    /**
     * @return array{quotes: list<Quote>, total: int, limit: int, offset: int}
     */
    public function quotes(string $tenantId, string $productId, int $limit, int $offset): array
    {
        return [
            'quotes' => $this->sales->listQuotes($tenantId, $productId, $limit, $offset),
            'total' => $this->sales->countQuotes($tenantId, $productId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function showQuote(string $tenantId, string $productId, string $quoteId): Quote
    {
        $quote = $this->sales->findQuote($tenantId, $productId, $quoteId);

        if ($quote === null) {
            throw new NotFoundException('Quote not found.', [], 'QUOTE_NOT_FOUND');
        }

        return $quote;
    }

    /**
     * Prices an offer for a tenant, valid until a date.
     *
     * The customer is snapshotted now, as on an invoice: a quote is a
     * document that was sent, and who it was addressed to is part of what
     * was sent rather than a live lookup.
     */
    public function quoteFor(
        string $tenantId,
        string $productId,
        string $offerId,
        int $validityDays,
        ?string $actorUserId,
    ): Quote {
        $offer = SubscribedOffer::from($this->catalogue->offerOnSale($productId, $offerId));
        $profile = $this->profiles->find($tenantId);

        $line = InvoiceLine::of(
            1,
            $offer->lineDescription(),
            1,
            Money::of($offer->version->priceMinorUnits, $offer->version->currency),
            Money::zero($offer->version->currency),
            $this->taxation->calculate(
                $tenantId,
                $productId,
                $offer->version->priceMinorUnits,
                $offer->version->currency,
                null,
                null,
            )->rateBasisPoints,
            $offer->version->id,
        );

        return $this->sales->createQuote(
            $tenantId,
            $productId,
            $offer->version->id,
            [$line],
            $profile?->snapshot() ?? [],
            (new DateTimeImmutable())->modify(sprintf('+%d days', $validityDays)),
            $actorUserId,
        );
    }

    public function rejectQuote(string $tenantId, string $productId, string $quoteId, ?string $actorUserId): Quote
    {
        $quote = $this->showQuote($tenantId, $productId, $quoteId);

        QuoteStatus::assertPermits($quote->status, QuoteStatus::REJECTED);

        return $this->sales->transitionQuote($quote, QuoteStatus::REJECTED, $actorUserId);
    }

    /**
     * @return array{orders: list<Order>, total: int, limit: int, offset: int}
     */
    public function orders(string $tenantId, string $productId, int $limit, int $offset): array
    {
        return [
            'orders' => $this->sales->listOrders($tenantId, $productId, $limit, $offset),
            'total' => $this->sales->countOrders($tenantId, $productId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function showOrder(string $tenantId, string $productId, string $orderId): Order
    {
        $order = $this->sales->findOrder($tenantId, $productId, $orderId);

        if ($order === null) {
            throw new NotFoundException('Order not found.', [], 'ORDER_NOT_FOUND');
        }

        return $order;
    }

    /**
     * Accepts a quote and places the order it becomes.
     *
     * The lines are the quote's, copied. That is the point of quoting: the
     * customer agreed to those amounts, and re-deriving them from an offer
     * that may have been repriced since would bill them for something else.
     */
    public function acceptQuote(string $tenantId, string $productId, string $quoteId, ?string $actorUserId): Order
    {
        $quote = $this->showQuote($tenantId, $productId, $quoteId);

        // The clock, not the column. A quote can sit at SENT for a year after
        // it lapsed because nothing has swept it, and honouring it would
        // hold a price that expired.
        if ($quote->hasLapsedAt(new DateTimeImmutable())) {
            throw new ConflictException(
                'QUOTE_EXPIRED',
                'That quote is no longer valid.',
                ['valid_until' => $quote->validUntil->format(DATE_RFC3339)],
            );
        }

        QuoteStatus::assertPermits($quote->status, QuoteStatus::ACCEPTED);

        return $this->sales->placeOrder(
            $tenantId,
            $productId,
            $quote,
            $quote->offerVersionId,
            $quote->lines,
            $actorUserId,
        );
    }

    /**
     * Buys an offer without a quote first, which is the self-serve path.
     */
    public function order(string $tenantId, string $productId, string $offerId, ?string $actorUserId): Order
    {
        $offer = SubscribedOffer::from($this->catalogue->offerOnSale($productId, $offerId));

        $line = InvoiceLine::of(
            1,
            $offer->lineDescription(),
            1,
            Money::of($offer->version->priceMinorUnits, $offer->version->currency),
            Money::zero($offer->version->currency),
            $this->taxation->calculate(
                $tenantId,
                $productId,
                $offer->version->priceMinorUnits,
                $offer->version->currency,
                null,
                null,
            )->rateBasisPoints,
            $offer->version->id,
        );

        return $this->sales->placeOrder($tenantId, $productId, null, $offer->version->id, [$line], $actorUserId);
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
}
