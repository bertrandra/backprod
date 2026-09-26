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
     * How much of an invoice has already been credited, in minor units
     * (2026-09-26).
     *
     * Asked before a partial credit is raised, because nothing else bounds
     * one. Credit an invoice in full and then refund the card and, with no
     * such question, the platform would reverse the same VAT twice and
     * declare a negative sale that never happened.
     */
    public function creditedOn(string $invoiceId): int;

    /**
     * Issues a credit note against an invoice and moves that invoice to
     * CREDITED, in one transaction.
     *
     * The two are inseparable: a credit note whose invoice still reads as
     * owed, or an invoice marked credited with no document behind it, are
     * both states an auditor would ask about and neither is recoverable by
     * looking at the other.
     *
     * `$alsoRecord` runs **inside** that transaction, after the document
     * exists and before it commits (2026-09-26) — the same shape `issue()` on
     * {@see InvoiceRepository} has, and for the same reason. It is how the
     * reversing fiscal fact of §25.3 is written atomically with the credit
     * note that produced it: a credit note with no VAT transaction leaves the
     * declaration claiming tax on a sale that was undone.
     *
     * `$closesTheInvoice` is what decides that transition (2026-09-26). A
     * partial credit leaves the invoice where it was: a document credited by
     * a tenth is not an undone document, and marking it CREDITED would say
     * the customer owes nothing of a debt they have mostly paid.
     *
     * @param list<InvoiceLine>       $lines
     * @param array<string, mixed>    $supplier
     * @param array<string, mixed>    $customer
     * @param (callable(CreditNote): void)|null $alsoRecord
     */
    public function issue(
        Invoice $invoice,
        array $lines,
        array $supplier,
        array $customer,
        ?string $reason,
        ?string $actorUserId,
        bool $closesTheInvoice = true,
        ?callable $alsoRecord = null,
    ): CreditNote;

    /**
     * The same, **inside the caller's transaction** and opening none of its
     * own — the shape {@see InvoiceRepository::applyIssue} has, for the same
     * reason (2026-09-26).
     *
     * A refund needs it: the money going back and the document that makes it
     * legal are one write or neither, exactly as the early-termination charge
     * is raised on the cancellation's own transaction. A refund recorded with
     * its credit note missing leaves the fiscal fact declared while the money
     * has gone, which is the defect this argument exists to close.
     *
     * @param list<InvoiceLine>       $lines
     * @param array<string, mixed>    $supplier
     * @param array<string, mixed>    $customer
     * @param (callable(CreditNote): void)|null $alsoRecord
     */
    public function applyIssue(
        Invoice $invoice,
        array $lines,
        array $supplier,
        array $customer,
        ?string $reason,
        ?string $actorUserId,
        bool $closesTheInvoice = true,
        ?callable $alsoRecord = null,
    ): CreditNote;
}
