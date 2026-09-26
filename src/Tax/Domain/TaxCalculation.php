<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * The motivated result of applying a rule (§25.3).
 *
 * A bare rate is not an answer. When an invoice surprises its recipient, the
 * question is never "what rate?" but "why that one?", and a number cannot be
 * argued with. So the calculation carries the rule that chose it, the regime
 * it chose, the country of taxation it settled on, and the mention the
 * document must bear — which for reverse charge and exemption is a legal
 * requirement, not a nicety.
 *
 * It is also what `POST /tax/calculate` returns, which makes the diagnostic
 * tool and the invoicing path the same code rather than two that can drift.
 */
final class TaxCalculation
{
    /**
     * @param list<string> $reasons why this rule, in order of application
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $regime,
        public readonly string $countryOfTaxation,
        public readonly int $rateBasisPoints,
        public readonly int $taxableBase,
        public readonly int $vatAmount,
        public readonly string $currency,
        public readonly bool $reverseCharge,
        public readonly string $customerTaxStatus,
        public readonly ?string $customerTaxNumber,
        public readonly ?string $legalMention,
        public readonly array $reasons,
    ) {
    }

    /**
     * VAT on a base, in minor units, from a rate in basis points.
     *
     * Integer arithmetic throughout, as everywhere money is handled (§25).
     * `intdiv` after multiplying keeps the rounding in one place and away
     * from floats, which cannot represent a rate exactly and would put
     * one-cent differences into a legal document.
     *
     * Rounds half up, on the positive amounts this platform bills.
     */
    public static function vatOn(int $base, int $basisPoints): int
    {
        return intdiv($base * $basisPoints + 5_000, 10_000);
    }

    /**
     * The taxable base inside a gross amount — the inverse of {@see vatOn},
     * for the one case where the money is known and the base is not.
     *
     * That case is a partial credit note (2026-09-26). It is priced from
     * money that has **already moved**: a card has been credited a gross
     * figure, and the document has to say exactly that figure or it
     * describes a different movement than the one that happened.
     *
     * So the rounding falls here, on the base, and the VAT is whatever is
     * left over — never a second rounding of its own. Computing both
     * independently leaves a cent belonging to neither, and the database
     * says the same thing in its own words:
     * `credit_notes_gross_is_net_plus_vat`.
     *
     * Integer arithmetic, half up, like everything else that touches money.
     */
    public static function baseOfGross(int $gross, int $basisPoints): int
    {
        $divisor = 10_000 + $basisPoints;

        return intdiv($gross * 10_000 + intdiv($divisor, 2), $divisor);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'regime' => $this->regime,
            'country_of_taxation' => $this->countryOfTaxation,
            'rate_basis_points' => $this->rateBasisPoints,
            'taxable_base' => $this->taxableBase,
            'vat_amount' => $this->vatAmount,
            'currency' => $this->currency,
            'reverse_charge' => $this->reverseCharge,
            'customer_tax_status' => $this->customerTaxStatus,
            'legal_mention' => $this->legalMention,
            'reasons' => $this->reasons,
        ];
    }
}
