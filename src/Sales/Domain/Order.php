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
    ) {
    }

    public function isComplete(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
