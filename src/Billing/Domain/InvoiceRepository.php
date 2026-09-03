<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use DateTimeImmutable;

/**
 * Invoices for one tenant and product.
 *
 * Reads are scoped by both, always: an invoice is the most sensitive tenant
 * data this platform holds, and the caller's resolved context is the only
 * sanctioned source of either id (ADR-015).
 */
interface InvoiceRepository
{
    /**
     * @return list<Invoice>
     */
    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset): array;

    public function countForTenant(string $tenantId, string $productId): int;

    public function find(string $tenantId, string $productId, string $invoiceId): ?Invoice;

    /**
     * Issues an invoice in one transaction: writes the document and its
     * lines, records the tax per rate, allocates the legal number, and
     * appends to the ledger.
     *
     * The number must be allocated from the existing maximum under a lock,
     * not from a database sequence. Sequences skip numbers when a
     * transaction rolls back, and a missing invoice number is a question
     * from an auditor rather than a cosmetic gap.
     *
     * @param list<InvoiceLine> $lines
     * @param array<string, mixed> $supplier
     * @param array<string, mixed> $customer
     */
    public function issue(
        string $tenantId,
        string $productId,
        ?string $subscriptionId,
        array $lines,
        array $supplier,
        array $customer,
        string $jurisdiction,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?string $paymentTerms,
        ?string $actorUserId,
    ): Invoice;

    /**
     * Moves an invoice to a new status, recording the move in the ledger.
     * The caller has already checked that the transition is legal.
     */
    public function transition(Invoice $invoice, string $status, ?string $actorUserId): Invoice;

    /**
     * The same move, without a transaction of its own, for a caller that
     * already has one open on the same connection.
     *
     * It exists because a payment settling its invoice must be atomic with
     * the payment itself — a collected payment and an invoice still saying
     * it is owed must never be observable, not even after a crash between
     * them — and nesting one transaction inside another is a property of the
     * driver rather than of this design. One writer, two entry points: the
     * transactional one above delegates here.
     *
     * Calling this outside a transaction writes the status and the ledger
     * entry unatomically, so don't.
     */
    public function applyTransition(Invoice $invoice, string $status, ?string $actorUserId): void;
}
