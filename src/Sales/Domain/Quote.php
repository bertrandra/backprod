<?php

declare(strict_types=1);

namespace App\Sales\Domain;

use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use DateTimeImmutable;

/**
 * A priced proposal, valid until a date.
 *
 * It has no number. French law numbers invoices and credit notes in unbroken
 * sequences; a devis is not subject to that, and inventing a second scheme
 * with different rules is how one later gets mistaken for the legal one.
 *
 * Its lines are the same shape as an invoice's, because a quote becoming an
 * invoice should copy rather than re-derive: what was quoted is what is
 * billed.
 */
final class Quote
{
    /**
     * @param list<InvoiceLine>    $lines
     * @param array<string, mixed> $customer
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $offerVersionId,
        public readonly string $status,
        public readonly Money $net,
        public readonly Money $vat,
        public readonly Money $gross,
        public readonly DateTimeImmutable $validUntil,
        public readonly array $customer,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?DateTimeImmutable $decidedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly array $lines,
    ) {
    }

    /**
     * Whether this quote can still be acted on at a given moment.
     *
     * Both halves matter, and the clock is the half that is easy to forget: a
     * quote can sit at SENT for a year after it lapsed, because nothing has
     * swept it. Asking the status alone would honour a price that expired.
     */
    public function isOpenAt(DateTimeImmutable $moment): bool
    {
        return $this->status === QuoteStatus::SENT && $moment < $this->validUntil;
    }

    public function hasLapsedAt(DateTimeImmutable $moment): bool
    {
        return $this->status === QuoteStatus::SENT && $moment >= $this->validUntil;
    }
}
