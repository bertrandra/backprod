<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use DateTimeImmutable;

/**
 * A document that has been, or is about to be, sent to a customer.
 *
 * Its totals and its parties are stored, not computed from anything current.
 * Re-pricing an offer, renaming a company or moving office changes nothing
 * about an invoice already issued — which is the point of §25 and the reason
 * this object holds snapshots rather than references.
 */
final class Invoice
{
    /**
     * @param list<InvoiceLine>       $lines
     * @param array<string, mixed>    $supplier
     * @param array<string, mixed>    $customer
     * @param list<TaxRecord>         $taxes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly ?string $subscriptionId,
        public readonly ?string $number,
        public readonly string $status,
        public readonly Money $net,
        public readonly Money $vat,
        public readonly Money $gross,
        public readonly ?DateTimeImmutable $issuedAt,
        public readonly ?DateTimeImmutable $dueAt,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly ?DateTimeImmutable $periodStart,
        public readonly ?DateTimeImmutable $periodEnd,
        public readonly ?string $paymentTerms,
        public readonly array $supplier,
        public readonly array $customer,
        public readonly array $lines,
        public readonly array $taxes,
    ) {
    }

    public function isFinal(): bool
    {
        return InvoiceStatus::isFinal($this->status);
    }
}
