<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * The two parties a regime is decided between (2026-09-26).
 *
 * §25.3's chain begins `profil + prestation → règle`, and the profile it
 * means is *a pair*: who is selling and who is buying. Until today the pair
 * was implicit — the platform's settings for the product, the tenant's
 * profile as customer — because the platform was the only supplier there was.
 *
 * ADR-055 ended that. An organisation selling a seat to one of its own people
 * is a supplier, with its own country, its own VAT status and its own
 * declaration, and the sale must be decided between *it* and the person. The
 * defect this object exists to make impossible is the one that survived
 * ADR-055 by a day: the invoice named the organisation and the person while
 * the regime was decided for the platform and the organisation, so a
 * verified French tenant bought a domestic B2C seat under Irish reverse
 * charge at 0% — and the fiscal fact went into the platform's VAT return.
 *
 * Carried as values, never as ids: everything the decision needs is here, so
 * no branch of {@see TaxRule} can reach past it to ask who the product's
 * supplier is.
 */
final class TaxableSale
{
    public function __construct(
        public readonly SupplierTaxSettings $supplier,
        public readonly CustomerTaxProfile $customer,
        /**
         * The organisation whose gapless series and whose VAT return this
         * sale belongs to; null when the platform sells (ADR-054).
         *
         * The same value the document is numbered under, travelling with the
         * regime so a transaction cannot be filed in one party's return while
         * the invoice was raised by the other.
         */
        public readonly ?string $issuerTenantId = null,
    ) {
    }
}
