<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Domain\TaxCalculation;
use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\TaxRate;
use App\Tax\Domain\VatDeclaration;
use App\Tax\Domain\VatReportingPeriod;
use App\Tax\Domain\VatTransaction;

/**
 * Fiscal objects, as JSON.
 *
 * The verification status is always rendered alongside the number, never
 * instead of it. "FR123456789" tells a reader nothing about whether reverse
 * charge is available; "FR123456789, verified on the 3rd" does.
 */
final class TaxPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function profile(CustomerTaxProfile $profile): array
    {
        $identification = $profile->identification;

        return [
            'tenant_id' => $profile->tenantId,
            'customer_kind' => $profile->customerKind,
            'country_code' => $profile->countryCode,
            'taxable_person' => $profile->taxablePerson,
            'location_evidence' => $profile->locationEvidence,
            'vat_number' => $identification?->vatNumber,
            'vat_number_status' => $identification?->status,
            'vat_number_verified_at' => $identification?->verifiedAt?->format(DATE_ATOM),
            'vat_number_country' => $identification === null
                ? null
                : TaxIdentification::countryForPrefix($identification->countryPrefix),
            'reverse_charge_available' => $profile->isVerifiedBusiness(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rate(TaxRate $rate): array
    {
        return [
            'country_code' => $rate->countryCode,
            'rate_kind' => $rate->rateKind,
            'basis_points' => $rate->basisPoints,
            'valid_from' => $rate->validFrom->format(DATE_ATOM),
            'valid_until' => $rate->validUntil?->format(DATE_ATOM),
            'source' => $rate->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function calculation(TaxCalculation $calculation): array
    {
        return $calculation->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public static function transaction(VatTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'invoice_id' => $transaction->invoiceId,
            'credit_note_id' => $transaction->creditNoteId,
            'country' => $transaction->country,
            'customer_tax_number' => $transaction->customerTaxNumber,
            'customer_tax_status' => $transaction->customerTaxStatus,
            'supply_type' => $transaction->supplyType,
            'taxable_base' => $transaction->taxableBase,
            'vat_rate' => $transaction->vatRate,
            'vat_amount' => $transaction->vatAmount,
            'currency' => $transaction->currency,
            'vat_regime' => $transaction->vatRegime,
            'rule_id' => $transaction->ruleId,
            'reverse_charge' => $transaction->reverseCharge,
            'transaction_date' => $transaction->transactionDate->format(DATE_ATOM),
            'product' => $transaction->productCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function period(VatReportingPeriod $period): array
    {
        return [
            'id' => $period->id,
            'jurisdiction' => $period->jurisdiction,
            'period_kind' => $period->periodKind,
            'starts_on' => $period->startsOn->format('Y-m-d'),
            'ends_on' => $period->endsOn->format('Y-m-d'),
            'status' => $period->status,
            'closed_at' => $period->closedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function declaration(VatDeclaration $declaration): array
    {
        return [
            'id' => $declaration->id,
            'period_id' => $declaration->periodId,
            'currency' => $declaration->currency,
            'total_base' => $declaration->totalBase,
            'total_vat' => $declaration->totalVat,
            'transaction_count' => $declaration->transactionCount,
            'breakdown' => $declaration->breakdown,
            'created_at' => $declaration->createdAt->format(DATE_ATOM),
        ];
    }
}
