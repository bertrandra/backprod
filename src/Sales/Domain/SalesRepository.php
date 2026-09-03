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
     * Fulfils an order: starts the subscription, raises the invoice and
     * completes the order, in one transaction.
     *
     * The work itself comes from the supplied {@see OrderFulfilment}, so this
     * never learns what a subscription or an invoice is. What it owns is the
     * transaction — and that is the point, because the schema refuses a
     * completed order that does not name both.
     */
    public function fulfilOrder(Order $order, OrderFulfilment $fulfilment, ?string $actorUserId): Order;

    public function cancelOrder(Order $order, ?string $actorUserId): Order;
}
