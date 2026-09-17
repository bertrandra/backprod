<?php

declare(strict_types=1);

namespace App\Sales\Domain;

use App\Billing\Domain\InvoiceLine;
use DateTimeImmutable;

/**
 * Quotes and the orders they become.
 *
 * Everything is scoped by tenant and product: a quote is tenant data, and the
 * caller's resolved context is the only sanctioned source of both (ADR-015).
 */
interface SalesRepository
{
    /**
     * @return list<Quote>
     */
    public function listQuotes(string $tenantId, string $productId, int $limit, int $offset): array;

    public function countQuotes(string $tenantId, string $productId): int;

    public function findQuote(string $tenantId, string $productId, string $quoteId): ?Quote;

    /**
     * @param list<InvoiceLine>    $lines
     * @param array<string, mixed> $customer
     */
    public function createQuote(
        string $tenantId,
        string $productId,
        string $offerVersionId,
        array $lines,
        array $customer,
        DateTimeImmutable $validUntil,
        ?string $actorUserId,
    ): Quote;

    public function transitionQuote(Quote $quote, string $status, ?string $actorUserId): Quote;

    /**
     * @return list<Order>
     */
    public function listOrders(string $tenantId, string $productId, int $limit, int $offset): array;

    public function countOrders(string $tenantId, string $productId): int;

    public function findOrder(string $tenantId, string $productId, string $orderId): ?Order;

    /**
     * Places an order, optionally from a quote, and marks that quote accepted
     * in the same transaction.
     *
     * One transaction because the unique index on `orders.quote_id` is what
     * stops a quote being ordered twice, and it can only do that if the two
     * writes cannot be separated.
     *
     * @param list<InvoiceLine> $lines
     */
    public function placeOrder(
        string $tenantId,
        string $productId,
        ?Quote $quote,
        string $offerVersionId,
        array $lines,
        ?string $actorUserId,
    ): Order;

    /**
     * Fulfils an order: raises its invoice and parks it until that invoice is
     * paid, in one transaction.
     *
     * An order with nothing to collect completes here instead, in the same
     * transaction — a free offer has no payment to wait for, and parking it
     * would strand it behind money that is never coming.
     *
     * The work itself comes from the supplied {@see OrderFulfilment}, so this
     * never learns what a subscription or an invoice is. What it owns is the
     * transaction.
     */
    public function fulfilOrder(Order $order, OrderFulfilment $fulfilment, ?string $actorUserId): Order;

    /**
     * The order waiting on an invoice, if a sale is waiting on it at all.
     *
     * Most invoices have no order behind them — a subscription billed
     * directly, a document raised by hand — so nothing found is the ordinary
     * answer rather than an anomaly.
     */
    public function findOrderAwaitingPayment(string $tenantId, string $productId, string $invoiceId): ?Order;

    /**
     * Completes an order whose invoice has just been paid, without a
     * transaction of its own.
     *
     * The caller already holds one: this runs inside the write that marked
     * the invoice paid, whether that was a provider's webhook or an operator
     * reconciling a transfer. Money collected and a subscription still off
     * must never be observable, and nesting one transaction inside another is
     * a property of the driver rather than of this design.
     */
    public function applyCompleteOrder(Order $order, OrderFulfilment $fulfilment, ?string $actorUserId): void;

    /**
     * Records that an order whose invoice has just been paid could **not**
     * be completed, without a transaction of its own, for the same reason
     * as {@see applyCompleteOrder}.
     *
     * The order is left where it stands — still awaiting, though its invoice
     * is now paid — and a ledger row names why. Nothing is pretended in
     * either direction: the money arrived and is recorded as such; the
     * subscription did not start and is not claimed to have. What that
     * leaves is a paid document with nothing delivered against it, which
     * is precisely the anomaly an operator should be shown rather than one
     * the platform should resolve on its own by starting something twice.
     *
     * @param array<string, mixed> $detail why, in the ledger's own words
     */
    public function applyHoldOrder(Order $order, array $detail, ?string $actorUserId): void;

    public function cancelOrder(Order $order, ?string $actorUserId): Order;

    /**
     * Moves quotes past their date to EXPIRED, and says how many moved.
     *
     * This does not change what a lapsed quote *means* — acceptance has asked
     * the clock since M6, so an unswept quote was never honoured. It makes the
     * column agree with the clock, which is what a listing shows.
     */
    public function expireLapsedQuotes(): int;
}
