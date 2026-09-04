<?php

declare(strict_types=1);

namespace App\Finance\Domain;

/**
 * One month of turnover, in one currency (§25.2).
 *
 * Turnover is what was **invoiced**, not what was collected: §25.2 asks for
 * revenue *and* for unpaid invoices and receivables, which are only different
 * questions if revenue is the billed figure.
 *
 * Credits sit beside it rather than inside it. Netting them into one number
 * would answer "what did we bill?" and "what do we keep?" at once, and those
 * have different audiences.
 */
final class RevenuePeriod
{
    public function __construct(
        public readonly string $periodStart,
        public readonly string $currency,
        public readonly int $netMinorUnits,
        public readonly int $vatMinorUnits,
        public readonly int $grossMinorUnits,
        public readonly int $creditedMinorUnits,
        public readonly int $invoicesIssued,
        public readonly int $invoicesPaid,
        public readonly bool $closed,
    ) {
    }
}
