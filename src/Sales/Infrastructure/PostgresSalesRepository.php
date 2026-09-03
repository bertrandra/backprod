<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Sales\Domain\Order;
use App\Sales\Domain\OrderFulfilment;
use App\Sales\Domain\Quote;
use App\Sales\Domain\QuoteStatus;
use App\Sales\Domain\SalesRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use stdClass;

/**
 * Quotes and orders in PostgreSQL.
 *
 * {@see self::fulfilOrder()} is the transaction that makes §20's chain
 * traceable: the subscription, the invoice and the order's completion are one
 * write or none. `orders_completed_is_traceable` is the backstop; this is
 * what stops it ever being hit.
 */
final class PostgresSalesRepository implements SalesRepository
{
    private const QUOTE_COLUMNS = <<<'SQL'
        id, tenant_id, product_id, offer_version_id, status, currency,
        net_minor_units, vat_minor_units, gross_minor_units, valid_until,
        customer_snapshot, sent_at, decided_at, created_at
        SQL;

    private const ORDER_COLUMNS = <<<'SQL'
        id, tenant_id, product_id, quote_id, offer_version_id, subscription_id,
        invoice_id, status, currency, net_minor_units, vat_minor_units,
        gross_minor_units, completed_at, created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function listQuotes(string $tenantId, string $productId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        return $this->hydrateQuotes($this->connection->fetchAllAssociative(
            'SELECT ' . self::QUOTE_COLUMNS . <<<'SQL'
                 FROM quotes
                WHERE tenant_id = :tenantId AND product_id = :productId
                ORDER BY created_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        ));
    }

    public function countQuotes(string $tenantId, string $productId): int
    {
        return $this->countIn('quotes', $tenantId, $productId);
    }

    public function findQuote(string $tenantId, string $productId, string $quoteId): ?Quote
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($quoteId)) {
            return null;
        }

        return $this->hydrateQuotes($this->connection->fetchAllAssociative(
            'SELECT ' . self::QUOTE_COLUMNS . <<<'SQL'
                 FROM quotes
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['id' => $quoteId, 'tenantId' => $tenantId, 'productId' => $productId],
        ))[0] ?? null;
    }

    public function createQuote(
        string $tenantId,
        string $productId,
        string $offerVersionId,
        array $lines,
        array $customer,
        DateTimeImmutable $validUntil,
        ?string $actorUserId,
    ): Quote {
        if ($lines === []) {
            throw new RuntimeException('A quote must have at least one line.');
        }

        return $this->connection->transactional(function () use (
            $tenantId,
            $productId,
            $offerVersionId,
            $lines,
            $customer,
            $validUntil,
            $actorUserId,
        ): Quote {
            [$currency, $net, $vat] = self::totals($lines);

            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO quotes
                        (tenant_id, product_id, offer_version_id, status, currency,
                         net_minor_units, vat_minor_units, gross_minor_units,
                         valid_until, customer_snapshot, sent_at, created_by)
                    VALUES (:tenantId, :productId, :version, 'SENT', :currency,
                            :net, :vat, :gross, :validUntil, CAST(:customer AS jsonb), now(), :actor)
                    RETURNING id
                    SQL,
                [
                    'tenantId' => $tenantId,
                    'productId' => $productId,
                    'version' => $offerVersionId,
                    'currency' => $currency,
                    'net' => $net,
                    'vat' => $vat,
                    'gross' => $net + $vat,
                    'validUntil' => $validUntil->format('Y-m-d H:i:s.uP'),
                    'customer' => self::encode($customer),
                    'actor' => $actorUserId,
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to create a quote.');
            }

            $this->insertLines('quote_lines', 'quote_id', $id, $lines);

            $quote = $this->findQuote($tenantId, $productId, $id);

            if ($quote === null) {
                throw new RuntimeException('The quote vanished during the transaction that created it.');
            }

            return $quote;
        });
    }

    public function transitionQuote(Quote $quote, string $status, ?string $actorUserId): Quote
    {
        return $this->connection->transactional(function () use ($quote, $status, $actorUserId): Quote {
            $this->applyQuoteTransition($quote, $status);

            if ($status === QuoteStatus::ACCEPTED) {
                $this->record(
                    $quote->tenantId,
                    $quote->productId,
                    'QUOTE_ACCEPTED',
                    null,
                    $quote->gross,
                    $actorUserId,
                );
            }

            $updated = $this->findQuote($quote->tenantId, $quote->productId, $quote->id);

            if ($updated === null) {
                throw new RuntimeException('The quote vanished during a change to it.');
            }

            return $updated;
        });
    }

    public function listOrders(string $tenantId, string $productId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        return $this->hydrateOrders($this->connection->fetchAllAssociative(
            'SELECT ' . self::ORDER_COLUMNS . <<<'SQL'
                 FROM orders
                WHERE tenant_id = :tenantId AND product_id = :productId
                ORDER BY created_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        ));
    }

    public function countOrders(string $tenantId, string $productId): int
    {
        return $this->countIn('orders', $tenantId, $productId);
    }

    public function findOrder(string $tenantId, string $productId, string $orderId): ?Order
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($orderId)) {
            return null;
        }

        return $this->hydrateOrders($this->connection->fetchAllAssociative(
            'SELECT ' . self::ORDER_COLUMNS . <<<'SQL'
                 FROM orders
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['id' => $orderId, 'tenantId' => $tenantId, 'productId' => $productId],
        ))[0] ?? null;
    }

    public function placeOrder(
        string $tenantId,
        string $productId,
        ?Quote $quote,
        string $offerVersionId,
        array $lines,
        ?string $actorUserId,
    ): Order {
        if ($lines === []) {
            throw new RuntimeException('An order must have at least one line.');
        }

        return $this->connection->transactional(function () use (
            $tenantId,
            $productId,
            $quote,
            $offerVersionId,
            $lines,
            $actorUserId,
        ): Order {
            [$currency, $net, $vat] = self::totals($lines);

            // Before the order, so a quote that has already been ordered is
            // refused by `orders_quote_used_once` in this same transaction
            // rather than by a check that could be raced.
            if ($quote !== null) {
                $this->applyQuoteTransition($quote, QuoteStatus::ACCEPTED);
                $this->record($tenantId, $productId, 'QUOTE_ACCEPTED', null, $quote->gross, $actorUserId);
            }

            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO orders
                        (tenant_id, product_id, quote_id, offer_version_id, status, currency,
                         net_minor_units, vat_minor_units, gross_minor_units, placed_by)
                    VALUES (:tenantId, :productId, :quote, :version, 'PENDING', :currency,
                            :net, :vat, :gross, :actor)
                    RETURNING id
                    SQL,
                [
                    'tenantId' => $tenantId,
                    'productId' => $productId,
                    'quote' => $quote?->id,
                    'version' => $offerVersionId,
                    'currency' => $currency,
                    'net' => $net,
                    'vat' => $vat,
                    'gross' => $net + $vat,
                    'actor' => $actorUserId,
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to place an order.');
            }

            $this->insertLines('order_lines', 'order_id', $id, $lines);
            $this->record(
                $tenantId,
                $productId,
                'ORDER_PLACED',
                $id,
                Money::of($net + $vat, $currency),
                $actorUserId,
            );

            return $this->requireOrder($tenantId, $productId, $id);
        });
    }

    public function fulfilOrder(Order $order, OrderFulfilment $fulfilment, ?string $actorUserId): Order
    {
        return $this->connection->transactional(function () use ($order, $fulfilment, $actorUserId): Order {
            $raised = $fulfilment->invoice($order);

            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE orders
                       SET invoice_id = :invoice,
                           status = 'AWAITING_PAYMENT',
                           updated_at = now()
                     WHERE id = :id
                    SQL,
                ['invoice' => $raised['invoice_id'], 'id' => $order->id],
            );

            if ($raised['awaiting_payment']) {
                return $this->requireOrder($order->tenantId, $order->productId, $order->id);
            }

            // Nothing to collect, so nothing to wait for. Completing here
            // rather than leaving a free order parked behind a payment that
            // will never arrive — read back first, because completing works
            // from the order as it now is, invoice and all.
            $this->applyCompleteOrder(
                $this->requireOrder($order->tenantId, $order->productId, $order->id),
                $fulfilment,
                $actorUserId,
            );

            return $this->requireOrder($order->tenantId, $order->productId, $order->id);
        });
    }

    public function findOrderAwaitingPayment(string $tenantId, string $productId, string $invoiceId): ?Order
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($invoiceId)) {
            return null;
        }

        return $this->hydrateOrders($this->connection->fetchAllAssociative(
            'SELECT ' . self::ORDER_COLUMNS . <<<'SQL'
                 FROM orders
                WHERE invoice_id = :invoice
                  AND tenant_id = :tenantId
                  AND product_id = :productId
                  AND status = 'AWAITING_PAYMENT'
                SQL,
            ['invoice' => $invoiceId, 'tenantId' => $tenantId, 'productId' => $productId],
        ))[0] ?? null;
    }

    public function applyCompleteOrder(Order $order, OrderFulfilment $fulfilment, ?string $actorUserId): void
    {
        $subscriptionId = $fulfilment->activate($order);

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE orders
                   SET subscription_id = :subscription,
                       status = 'COMPLETED',
                       completed_at = now(),
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['subscription' => $subscriptionId, 'id' => $order->id],
        );

        // The sale a payment settled, written where a payment can be read
        // back from: §20 wants the chain followable in both directions, and
        // the payment row is the end a reconciliation starts from.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE payments
                   SET order_id = :order, updated_at = now()
                 WHERE invoice_id = :invoice AND order_id IS NULL
                SQL,
            ['order' => $order->id, 'invoice' => $order->invoiceId],
        );

        $this->record(
            $order->tenantId,
            $order->productId,
            'ORDER_COMPLETED',
            $order->id,
            $order->gross,
            $actorUserId,
        );
    }

    public function cancelOrder(Order $order, ?string $actorUserId): Order
    {
        return $this->connection->transactional(function () use ($order, $actorUserId): Order {
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE orders
                       SET status = 'CANCELLED', cancelled_at = now(), updated_at = now()
                     WHERE id = :id
                    SQL,
                ['id' => $order->id],
            );

            $this->record(
                $order->tenantId,
                $order->productId,
                'ORDER_CANCELLED',
                $order->id,
                $order->gross,
                $actorUserId,
            );

            return $this->requireOrder($order->tenantId, $order->productId, $order->id);
        });
    }

    private function applyQuoteTransition(Quote $quote, string $status): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE quotes
                   SET status = :status,
                       decided_at = CASE
                           WHEN :status IN ('ACCEPTED', 'REJECTED') THEN now()
                           ELSE decided_at
                       END,
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['status' => $status, 'id' => $quote->id],
        );
    }

    private function countIn(string $table, string $tenantId, string $productId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        // The table name is interpolated because an identifier cannot be a
        // bound parameter; it comes from this class's own call sites and
        // never from a caller.
        $count = $this->connection->fetchOne(
            sprintf('SELECT count(*) FROM %s WHERE tenant_id = :tenantId AND product_id = :productId', $table),
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param list<InvoiceLine> $lines
     */
    private function insertLines(string $table, string $column, string $ownerId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->connection->executeStatement(
                sprintf(
                    <<<'SQL'
                        INSERT INTO %s
                            (%s, position, description, quantity, unit_price_minor_units,
                             discount_minor_units, net_minor_units, vat_rate_basis_points,
                             vat_minor_units, gross_minor_units)
                        VALUES (:owner, :position, :description, :quantity, :unitPrice,
                                :discount, :net, :rate, :vat, :gross)
                        SQL,
                    $table,
                    $column,
                ),
                [
                    'owner' => $ownerId,
                    'position' => $line->position,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unitPrice' => $line->unitPrice->minorUnits,
                    'discount' => $line->discount->minorUnits,
                    'net' => $line->net->minorUnits,
                    'rate' => $line->vatRateBasisPoints,
                    'vat' => $line->vat->minorUnits,
                    'gross' => $line->gross->minorUnits,
                ],
            );
        }
    }

    private function record(
        string $tenantId,
        string $productId,
        string $type,
        ?string $orderId,
        Money $amount,
        ?string $actorUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO financial_events
                    (tenant_id, product_id, type, order_id, amount_minor_units,
                     currency, actor_user_id, detail)
                VALUES (:tenantId, :productId, :type, :order, :amount, :currency, :actor, '{}')
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'type' => $type,
                'order' => $orderId,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency,
                'actor' => $actorUserId,
            ],
        );
    }

    private function requireOrder(string $tenantId, string $productId, string $id): Order
    {
        $order = $this->findOrder($tenantId, $productId, $id);

        if ($order === null) {
            throw new RuntimeException('The order vanished during the transaction that wrote it.');
        }

        return $order;
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @return array{string, int, int}
     */
    private static function totals(array $lines): array
    {
        $currency = $lines[0]->net->currency;
        $net = 0;
        $vat = 0;

        foreach ($lines as $line) {
            $net += $line->net->minorUnits;
            $vat += $line->vat->minorUnits;
        }

        return [$currency, $net, $vat];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Quote>
     */
    private function hydrateQuotes(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => Row::string($row, 'id'), $rows);
        $lines = $this->linesOf('quote_lines', 'quote_id', $ids, 'quotes');

        return array_map(
            static function (array $row) use ($lines): Quote {
                $id = Row::string($row, 'id');
                $currency = Row::string($row, 'currency');

                return new Quote(
                    $id,
                    Row::string($row, 'tenant_id'),
                    Row::string($row, 'product_id'),
                    Row::string($row, 'offer_version_id'),
                    Row::string($row, 'status'),
                    Money::of(Row::integer($row, 'net_minor_units'), $currency),
                    Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                    Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                    Row::timestamp($row, 'valid_until'),
                    self::decode($row, 'customer_snapshot'),
                    Row::nullableTimestamp($row, 'sent_at'),
                    Row::nullableTimestamp($row, 'decided_at'),
                    Row::timestamp($row, 'created_at'),
                    $lines[$id] ?? [],
                );
            },
            $rows,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Order>
     */
    private function hydrateOrders(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => Row::string($row, 'id'), $rows);
        $lines = $this->linesOf('order_lines', 'order_id', $ids, 'orders');

        return array_map(
            static function (array $row) use ($lines): Order {
                $id = Row::string($row, 'id');
                $currency = Row::string($row, 'currency');

                return new Order(
                    $id,
                    Row::string($row, 'tenant_id'),
                    Row::string($row, 'product_id'),
                    Row::nullableString($row, 'quote_id'),
                    Row::string($row, 'offer_version_id'),
                    Row::nullableString($row, 'subscription_id'),
                    Row::nullableString($row, 'invoice_id'),
                    Row::string($row, 'status'),
                    Money::of(Row::integer($row, 'net_minor_units'), $currency),
                    Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                    Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                    Row::nullableTimestamp($row, 'completed_at'),
                    Row::timestamp($row, 'created_at'),
                    $lines[$id] ?? [],
                );
            },
            $rows,
        );
    }

    /**
     * @param list<string> $ownerIds
     *
     * @return array<string, list<InvoiceLine>>
     */
    private function linesOf(string $table, string $column, array $ownerIds, string $ownerTable): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT %1$s, position, description, quantity, unit_price_minor_units,
                           discount_minor_units, net_minor_units, vat_rate_basis_points,
                           vat_minor_units, gross_minor_units,
                           (SELECT currency FROM %3$s o WHERE o.id = %2$s.%1$s) AS currency
                      FROM %2$s
                     WHERE %1$s IN (:ids)
                     ORDER BY %1$s, position
                    SQL,
                $column,
                $table,
                $ownerTable,
            ),
            ['ids' => $ownerIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $lines = [];

        foreach ($rows as $row) {
            $currency = Row::string($row, 'currency');

            $lines[Row::string($row, $column)][] = new InvoiceLine(
                Row::integer($row, 'position'),
                Row::string($row, 'description'),
                Row::integer($row, 'quantity'),
                Money::of(Row::integer($row, 'unit_price_minor_units'), $currency),
                Money::of(Row::integer($row, 'discount_minor_units'), $currency),
                Money::of(Row::integer($row, 'net_minor_units'), $currency),
                Row::integer($row, 'vat_rate_basis_points'),
                Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                null,
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        $encoded = json_encode($value === [] ? new stdClass() : $value);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row, string $column): array
    {
        $decoded = json_decode(Row::string($row, $column), true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
