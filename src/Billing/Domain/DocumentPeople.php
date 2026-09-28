<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Whom each billing document on a page concerns (2026-09-27), so every list
 * the administrator reads names the person beside the document.
 *
 * One question, asked of a page at a time, and answered by the same rule the
 * lists are filtered by — the holder of the subscription the document belongs
 * to, else the person the order that raised it was for. A document missing
 * from the answer concerns the organisation rather than anybody in it.
 *
 * @phpstan-type Person array{user_id: string, name: string|null, email: string|null}
 */
interface DocumentPeople
{
    // Every read takes the tenant, like `CollectedInvoices`: the ids reaching
    // it come from pages already scoped to one, and a boundary defended only by
    // every caller happening to be careful is not defended.

    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, Person> by invoice id
     */
    public function ofInvoices(string $tenantId, array $invoiceIds): array;

    /**
     * @param list<string> $creditNoteIds
     *
     * @return array<string, Person> by credit note id
     */
    public function ofCreditNotes(string $tenantId, array $creditNoteIds): array;

    /**
     * @param list<string> $paymentIds
     *
     * @return array<string, Person> by payment id
     */
    public function ofPayments(string $tenantId, array $paymentIds): array;

    /**
     * @param list<string> $orderIds
     *
     * @return array<string, Person> by order id
     */
    public function ofOrders(string $tenantId, array $orderIds): array;
}
