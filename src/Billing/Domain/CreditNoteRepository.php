<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Credit notes, scoped by tenant and product like every other document.
 */
interface CreditNoteRepository
{
    /**
     * @return list<CreditNote>
     */
    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null): array;

    public function countForTenant(string $tenantId, string $productId, ?string $ownedBy = null): int;

    public function find(string $tenantId, string $productId, string $creditNoteId, ?string $ownedBy = null): ?CreditNote;

    /**
     * Issues a credit note against an invoice and moves that invoice to
     * CREDITED, in one transaction.
     *
     * The two are inseparable: a credit note whose invoice still reads as
     * owed, or an invoice marked credited with no document behind it, are
     * both states an auditor would ask about and neither is recoverable by
     * looking at the other.
     *
     * @param list<InvoiceLine>    $lines
     * @param array<string, mixed> $supplier
     * @param array<string, mixed> $customer
     */
    public function issue(
        Invoice $invoice,
        array $lines,
        array $supplier,
        array $customer,
        ?string $reason,
        ?string $actorUserId,
    ): CreditNote;
}
