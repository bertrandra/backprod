<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * Which regime applies, and why (§25.3).
 *
 * The rule this class exists to enforce is the one §25.3 ends on:
 *
 *     ne jamais déduire un régime du seul code pays.
 *
 * A country says where somebody is. The regime depends on that *and* on
 * whether they are a taxable person, *and* on whether their VAT number was
 * verified rather than merely typed, *and* on what is being supplied. Every
 * one of those is a separate input here, and none of them is inferred from
 * another.
 *
 * The engine is deliberately a decision tree with named outcomes rather than
 * a table: each branch carries the reason it was taken, so an invoice that
 * surprises its recipient can be explained instead of defended.
 *
 * **Fail-closed on verification.** An unverified number is not a verified
 * one. If VIES was unreachable, the sale is invoiced at the standard regime
 * and the anomaly stays visible — it is never silently reclassified as
 * reverse-charged, because that is the reclassification that leaves the
 * supplier liable for tax it did not collect (risk R8).
 *
 * The legal mentions below are defaults. Their exact statutory wording is
 * configuration and must be confirmed against official sources before
 * production, exactly as §25.1's deadlines and §25.3's rates must be
 * (risks R3 and R7).
 */
final class TaxRule
{
    public const MENTION_REVERSE_CHARGE = 'Autoliquidation — TVA due par le preneur.';
    public const MENTION_OUT_OF_SCOPE = 'Opération non soumise à la TVA de l\'Union.';

    public function decide(
        SupplierTaxSettings $supplier,
        CustomerTaxProfile $customer,
        string $supplyType,
    ): RegimeDecision {
        $supplierCountry = strtoupper($supplier->countryCode);

        // The place of taxation is a computed, retained value — never the
        // supplier's country by default (§25.3). With no customer country
        // there is nothing to compute from, so the supplier's country is used
        // and the reason says so out loud rather than burying it.
        if (!$customer->hasKnownCountry()) {
            return new RegimeDecision(
                'fallback.unknown_country',
                VatRegime::STANDARD,
                $supplierCountry,
                false,
                null,
                [
                    'The customer has no recorded country.',
                    'Taxed in the supplier country, which is a fallback and not a determination.',
                ],
            );
        }

        $customerCountry = strtoupper((string) $customer->countryCode);

        // Domestic. Reverse charge is a cross-border mechanism, so a domestic
        // B2B sale is taxed exactly like a domestic B2C one — a rule that a
        // country-code-driven model gets wrong in the other direction.
        if ($customerCountry === $supplierCountry) {
            return new RegimeDecision(
                'domestic.standard',
                VatRegime::STANDARD,
                $supplierCountry,
                false,
                null,
                [
                    sprintf('Customer and supplier are both in %s.', $supplierCountry),
                    'A domestic supply is taxed at the domestic rate whether the customer is a business or not.',
                ],
            );
        }

        // Outside the EU VAT area.
        if (!EuMemberStates::vatRulesApplyTo($customerCountry)) {
            return new RegimeDecision(
                'export.outside_eu',
                VatRegime::OUT_OF_SCOPE,
                $customerCountry,
                false,
                self::MENTION_OUT_OF_SCOPE,
                [
                    sprintf('%s is outside the EU VAT area.', $customerCountry),
                    'The supply is outside the scope of Union VAT.',
                ],
            );
        }

        // Intra-Community, business customer.
        if ($customer->isBusiness()) {
            if ($customer->isVerifiedBusiness()) {
                return new RegimeDecision(
                    'eu.b2b.reverse_charge',
                    VatRegime::REVERSE_CHARGE,
                    $customerCountry,
                    true,
                    self::MENTION_REVERSE_CHARGE,
                    [
                        sprintf('Intra-Community supply from %s to %s.', $supplierCountry, $customerCountry),
                        'The customer is a taxable person.',
                        'Their VAT number was verified, so the tax is accounted for by the customer.',
                    ],
                );
            }

            // The fail-closed branch. A number that was typed but not proved
            // buys nothing, and neither does VIES being unreachable.
            $status = $customer->identification->status ?? 'none';

            return new RegimeDecision(
                'eu.b2b.unverified',
                VatRegime::STANDARD,
                $supplierCountry,
                false,
                null,
                [
                    sprintf('Intra-Community supply from %s to %s.', $supplierCountry, $customerCountry),
                    'The customer claims to be a taxable person.',
                    sprintf('Their VAT number is not verified (%s), so reverse charge does not apply.', $status),
                    'Taxed at the standard regime rather than reclassified.',
                ],
            );
        }

        // Intra-Community, consumer. Taxed where the consumer is, declared
        // through the one-stop shop — unless the supplier is below the
        // threshold, in which case the supplier's own rate applies.
        if (!$supplier->ossRegistered) {
            return new RegimeDecision(
                'eu.b2c.below_threshold',
                VatRegime::STANDARD,
                $supplierCountry,
                false,
                null,
                [
                    sprintf('Cross-border supply to a consumer in %s.', $customerCountry),
                    'The supplier is not registered for the one-stop shop.',
                    'Below the threshold the supplier country rate applies.',
                ],
            );
        }

        return new RegimeDecision(
            'eu.b2c.oss',
            VatRegime::OSS,
            $customerCountry,
            false,
            null,
            [
                sprintf('Cross-border supply to a consumer in %s.', $customerCountry),
                sprintf('The supply is %s.', strtolower(str_replace('_', ' ', $supplyType))),
                'Taxed in the customer country and declared through the one-stop shop.',
            ],
        );
    }
}
