<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\Domain\CustomerTaxProfile;
use App\Tax\Domain\SupplierTaxSettings;
use App\Tax\Domain\SupplyType;
use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\TaxRule;
use App\Tax\Domain\VatRegime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §25.3 ends on the rule this class exists to enforce: never infer a regime
 * from a country code alone. So what it *refuses* matters more than what it
 * allows, and the refusals come first here (§37.4).
 */
#[CoversClass(TaxRule::class)]
final class TaxRuleTest extends TestCase
{
    // --- what must not happen ------------------------------------------

    public function testAnUnverifiedNumberDoesNotBuyReverseCharge(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2B, true, TaxIdentification::UNVERIFIED),
            SupplyType::DIGITAL_SERVICES,
        );

        // The whole of risk R8. Invoicing at zero on a number nobody checked
        // leaves the supplier liable for the tax.
        self::assertSame(VatRegime::STANDARD, $decision->regime);
        self::assertFalse($decision->reverseCharge);
    }

    public function testViesBeingUnreachableDoesNotGrantReverseCharge(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2B, true, TaxIdentification::UNAVAILABLE),
            SupplyType::DIGITAL_SERVICES,
        );

        // Fail-closed. An outage must not silently reclassify a sale.
        self::assertSame(VatRegime::STANDARD, $decision->regime);
        self::assertFalse($decision->reverseCharge);
    }

    public function testAnInvalidNumberDoesNotGrantReverseCharge(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2B, true, TaxIdentification::INVALID),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::STANDARD, $decision->regime);
    }

    public function testABusinessWithNoNumberAtAllIsTaxed(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2B, true, null),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::STANDARD, $decision->regime);
    }

    public function testADomesticBusinessIsNotReverseCharged(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('FR', CustomerTaxProfile::B2B, true, TaxIdentification::VERIFIED),
            SupplyType::DIGITAL_SERVICES,
        );

        // Reverse charge is a cross-border mechanism. A model driven by the
        // country code alone gets this one wrong in the other direction.
        self::assertSame(VatRegime::STANDARD, $decision->regime);
        self::assertSame('FR', $decision->countryOfTaxation);
    }

    // --- what must happen ----------------------------------------------

    public function testAVerifiedIntraEuBusinessIsReverseCharged(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2B, true, TaxIdentification::VERIFIED),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::REVERSE_CHARGE, $decision->regime);
        self::assertTrue($decision->reverseCharge);
        self::assertSame('DE', $decision->countryOfTaxation);
        // The mention is a legal requirement, not a nicety.
        self::assertNotNull($decision->legalMention);
    }

    public function testACrossBorderConsumerIsTaxedWhereTheyAre(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2C, false, null),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::OSS, $decision->regime);
        self::assertSame('DE', $decision->countryOfTaxation);
    }

    public function testBelowTheThresholdTheSupplierRateApplies(): void
    {
        $decision = $this->rule()->decide(
            new SupplierTaxSettings('FR', false, SupplyType::DIGITAL_SERVICES, 'EUR'),
            $this->customer('DE', CustomerTaxProfile::B2C, false, null),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::STANDARD, $decision->regime);
        self::assertSame('FR', $decision->countryOfTaxation);
    }

    public function testOutsideTheUnionIsOutOfScope(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('US', CustomerTaxProfile::B2B, true, TaxIdentification::VERIFIED),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::OUT_OF_SCOPE, $decision->regime);
    }

    public function testGreeceIsGrAsACountryAndElOnANumber(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('GR', CustomerTaxProfile::B2B, true, TaxIdentification::VERIFIED, 'EL'),
            SupplyType::DIGITAL_SERVICES,
        );

        // A model assuming "VAT prefix = ISO country" is wrong for Greece,
        // and this is where that shows.
        self::assertSame(VatRegime::REVERSE_CHARGE, $decision->regime);
        self::assertSame('GR', $decision->countryOfTaxation);
        self::assertSame('GR', TaxIdentification::countryForPrefix('EL'));
    }

    public function testNorthernIrelandFollowsUnionRulesWithoutBeingACountryCode(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('XI', CustomerTaxProfile::B2B, true, TaxIdentification::VERIFIED, 'XI'),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::REVERSE_CHARGE, $decision->regime);
    }

    public function testAnUnknownCountryIsAFallbackThatSaysSo(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer(null, CustomerTaxProfile::B2C, false, null),
            SupplyType::DIGITAL_SERVICES,
        );

        self::assertSame(VatRegime::STANDARD, $decision->regime);
        self::assertSame('FR', $decision->countryOfTaxation);
        // §25.3 wants the place of taxation computed and retained, never
        // defaulted silently. Defaulting is allowed; hiding it is not.
        self::assertStringContainsString('fallback', implode(' ', $decision->reasons));
    }

    public function testEveryDecisionCarriesItsReasons(): void
    {
        $decision = $this->rule()->decide(
            $this->supplier(),
            $this->customer('DE', CustomerTaxProfile::B2B, true, TaxIdentification::UNVERIFIED),
            SupplyType::DIGITAL_SERVICES,
        );

        // A bare rate cannot be argued with when an invoice surprises its
        // recipient; a chain of reasons can.
        self::assertNotSame([], $decision->reasons);
        self::assertStringContainsString('not verified', implode(' ', $decision->reasons));
    }

    private function rule(): TaxRule
    {
        return new TaxRule();
    }

    private function supplier(): SupplierTaxSettings
    {
        return new SupplierTaxSettings('FR', true, SupplyType::DIGITAL_SERVICES, 'EUR');
    }

    private function customer(
        ?string $country,
        string $kind,
        bool $taxable,
        ?string $identificationStatus,
        ?string $prefix = null,
    ): CustomerTaxProfile {
        $identification = null;

        if ($identificationStatus !== null) {
            $usedPrefix = $prefix ?? (string) $country;
            $verified = $identificationStatus === TaxIdentification::VERIFIED
                || $identificationStatus === TaxIdentification::INVALID;

            $identification = new TaxIdentification(
                'identification',
                'tenant',
                $usedPrefix . '123456789',
                $usedPrefix,
                $identificationStatus,
                $verified ? new DateTimeImmutable() : null,
                $verified ? 'stub' : null,
                null,
            );
        }

        return new CustomerTaxProfile('tenant', $kind, $country, $taxable, [], $identification);
    }
}
