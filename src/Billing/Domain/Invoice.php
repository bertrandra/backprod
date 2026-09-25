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
        /**
         * The organisation that issued this document, and whose gapless
         * series its number belongs to; null when the platform issued it
         * (2026-09-25). Not the same question as `$tenantId`, which is the
         * isolation context and is the organisation either way — a seat sold
         * by Acme to one of its people and a platform subscription sold to
         * Acme are both Acme's rows, and only one of them is Acme's document.
         */
        public readonly ?string $issuerTenantId,
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
        /**
         * The language the document was issued in (ADR-050): snapshotted like
         * its parties, because a document is rendered in one language and
         * stays in it. What the PDF will be rendered in.
         */
        public readonly string $locale = 'en',
    ) {
    }

    public function isFinal(): bool
    {
        return InvoiceStatus::isFinal($this->status);
    }
}
