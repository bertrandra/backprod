<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use DateTimeImmutable;

/**
 * A credit note — an *avoir* — correcting an invoice.
 *
 * It is a legal document in its own right, with its own gapless number in its
 * own series, and it keeps its own snapshot for exactly the reason an invoice
 * does: it is a statement about a moment, and nothing that changes afterwards
 * may rewrite it.
 *
 * Its amounts are stored positive. Which way the money goes is the document's
 * kind, not the sign of its numbers — a credit note full of negatives would
 * make every total in every report depend on remembering to check.
 */
final class CreditNote
{
    /**
     * @param list<InvoiceLine> $lines
     * @param array<string, mixed> $supplier
     * @param array<string, mixed> $customer
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $invoiceId,
        public readonly string $number,
        public readonly ?string $reason,
        public readonly Money $net,
        public readonly Money $vat,
        public readonly Money $gross,
        public readonly DateTimeImmutable $issuedAt,
        public readonly array $supplier,
        public readonly array $customer,
        public readonly array $lines,
    ) {
    }
}
