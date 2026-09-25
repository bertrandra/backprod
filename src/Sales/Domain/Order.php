<?php

declare(strict_types=1);

namespace App\Sales\Domain;

use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use App\Commerce\Domain\Subscriber;
use DateTimeImmutable;

/**
 * What a tenant — or, for a seat, one of its people — committed to buy.
 *
 * The subscriber (§13.1) is decided at checkout and travels with the order
 * to the subscription it starts: `TENANT` for the organisation's own,
 * `USER` for a seat the person pays for themselves (2026-09-18).
 *
 * A completed order names both the subscription it started and the invoice it
 * raised — the schema insists on it — because non-negotiable #20 wants the
 * chain traceable end to end, and an order that says COMPLETED without either
 * is a sale nobody can follow.
 */
final class Order
{
    public const PENDING = 'PENDING';
    public const AWAITING_PAYMENT = 'AWAITING_PAYMENT';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';

    /**
     * @param list<InvoiceLine> $lines
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly ?string $quoteId,
        public readonly string $offerVersionId,
        public readonly ?string $subscriptionId,
        public readonly ?string $invoiceId,
        public readonly string $status,
        public readonly Money $net,
        public readonly Money $vat,
        public readonly Money $gross,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly array $lines,
        public readonly Subscriber $subscriber = new Subscriber(Subscriber::TENANT, null),
        /**
         * Who placed it (2026-09-25). The column has recorded this since
         * orders existed; the value object did not carry it, so nothing
         * downstream could use it.
         *
         * It matters now because **the person who bought owns what they
         * bought** (ADR-053): a subscription's owner is one of the people it
         * covers, and a subscription started by this chain was activated
         * with no actor at all. Every purchase made through quote → order →
         * payment therefore produced a subscription owned by nobody — which
         * entitled nobody the moment coverage stopped following membership.
         *
         * Null for an order placed with no person behind it, which is a
         * webhook completing one somebody else began, never a purchase
         * nobody made.
         */
        public readonly ?string $placedBy = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
