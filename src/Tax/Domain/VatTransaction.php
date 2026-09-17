<?php

declare(strict_types=1);

namespace App\Tax\Domain;

use DateTimeImmutable;

/**
 * The declarable fiscal fact (§25.3). Written once, never recalculated.
 *
 * This is object 4 of the six, and §25.3 calls it "le plus facile à perdre et
 * le plus coûteux à retrouver": which rule and which rate applied *at the
 * moment of the invoice*. The rate and the rule are stored as **values**,
 * never as a foreign key to a rate row that can move — a rate changed by law
 * must not shift one euro of VAT already invoiced, and a declaration replayed
 * two years later must give the same figure.
 *
 * A correction is a new transaction attached to a credit note, exactly as an
 * invoice is corrected by a credit note and never by a rewrite.
 */
final class VatTransaction
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $invoiceId,
        public readonly ?string $creditNoteId,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $country,
        public readonly ?string $customerTaxNumber,
        public readonly string $customerTaxStatus,
        public readonly string $supplyType,
        public readonly int $taxableBase,
        public readonly int $vatRate,
        public readonly int $vatAmount,
        public readonly string $currency,
        public readonly string $vatRegime,
        public readonly string $ruleId,
        public readonly bool $reverseCharge,
        public readonly DateTimeImmutable $transactionDate,
        /** The product's code, when read for a screen (2026-09-17); null straight after booking. */
        public readonly ?string $productCode = null,
    ) {
    }
}
