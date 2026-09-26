<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The invoices a page of payments collects, in one read (2026-09-26).
 *
 * A port of its own rather than a widening of {@see PaymentRepository}: a
 * payment is an attempt to move money and the customer's name is not one of
 * its facts. Keeping them apart is also what keeps the columns of every query
 * in that repository unqualified, which a join would not.
 *
 * **By the whole page, never one per row.** A screen showing twenty-five
 * payments must cost one extra query and not twenty-five — the shape
 * `docs/architecture-v2.md` warns about by name.
 *
 * **The tenant is a parameter and not an assumption.** The ids handed here
 * come from payments already scoped to one, so a read by id alone would
 * answer correctly today — and tenant isolation is a security boundary, which
 * is not defended by every caller happening to be careful. An id that escaped
 * its tenant would put a customer's name on somebody else's screen, and the
 * whole point of a boundary is that it holds when the reasoning above it is
 * wrong.
 */
interface CollectedInvoices
{
    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, Collected> by invoice id; an id belonging to
     *                                  another tenant, or to nothing, is
     *                                  simply absent
     */
    public function of(string $tenantId, array $invoiceIds): array;
}
