<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\Domain\TaxCalculation;
use App\Tax\Domain\TaxRate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * VAT arithmetic and rate windows.
 *
 * Both are places where a float or a "current value" would put a wrong number
 * on a legal document, and a legal document can only be corrected by issuing
 * a second one.
 */
#[CoversClass(TaxCalculation::class)]
#[CoversClass(TaxRate::class)]
final class TaxCalculationTest extends TestCase
{
    public function testVatIsIntegerArithmeticThroughout(): void
    {
        self::assertSame(2000, TaxCalculation::vatOn(10_000, 2000));
        self::assertSame(0, TaxCalculation::vatOn(0, 2000));
        self::assertSame(0, TaxCalculation::vatOn(10_000, 0));
    }

    public function testRoundingIsHalfUpAndDoesNotDrift(): void
    {
        // 0.2 of a cent rounds down, 0.6 rounds up. A float would represent
        // neither the rate nor the product exactly.
        self::assertSame(0, TaxCalculation::vatOn(1, 2000));
        self::assertSame(1, TaxCalculation::vatOn(3, 2000));
        self::assertSame(200, TaxCalculation::vatOn(999, 2000));
    }

    public function testTheFinnishRateWithHalfAPointIsExact(): void
    {
        // 25.5% is 2550 basis points, which is why the rate is stored in
        // basis points rather than percent.
        self::assertSame(3148, TaxCalculation::vatOn(12_345, 2550));
    }

    public function testTheBaseInsideAGrossAmountIsTheInverseOfTheVat(): void
    {
        // The ordinary way round: 100.00 net at 20% is 120.00 gross, and
        // 120.00 gross at 20% is 100.00 net.
        self::assertSame(10_000, TaxCalculation::baseOfGross(12_000, 2000));
        self::assertSame(12_345, TaxCalculation::baseOfGross(12_345, 0));
        self::assertSame(0, TaxCalculation::baseOfGross(0, 2000));
    }

    public function testTheMoneyIsExactAndTheBaseAbsorbsTheRounding(): void
    {
        // A partial credit note is priced from money that has already left,
        // so `net + vat` has to equal that money to the minor unit — the
        // database says so too (`credit_notes_gross_is_net_plus_vat`).
        //
        // 123 at 20% is the case that proves it cannot be done the other way
        // round: no whole base has `base + vatOn(base, 2000) == 123`, because
        // 102 gives 122 and 103 gives 124. Rounding the base and taking the
        // VAT as the remainder is the only split that adds up.
        self::assertSame(103, TaxCalculation::baseOfGross(123, 2000));
        self::assertSame(967, TaxCalculation::baseOfGross(1_160, 2000));
        self::assertSame(2_900, TaxCalculation::baseOfGross(3_480, 2000));

        foreach ([1, 2, 3, 99, 123, 1_160, 3_480, 999_999] as $gross) {
            foreach ([0, 550, 1_000, 2_000, 2_550] as $rate) {
                $base = TaxCalculation::baseOfGross($gross, $rate);

                // The VAT is the remainder, so it is never negative and
                // never larger than the money.
                self::assertGreaterThanOrEqual(0, $base);
                self::assertLessThanOrEqual($gross, $base);

                // And the base is the right base: within a minor unit of
                // the arithmetic everything else on the platform uses, which
                // — see 123 at 20% above — is as close as the two can get.
                self::assertLessThanOrEqual(
                    1,
                    abs($base + TaxCalculation::vatOn($base, $rate) - $gross),
                    sprintf('%d at %d basis points stays within a minor unit', $gross, $rate),
                );
            }
        }
    }

    public function testARateAppliesInsideItsWindowAndNotOutside(): void
    {
        $rate = new TaxRate(
            'rate',
            'EE',
            TaxRate::STANDARD,
            2200,
            new DateTimeImmutable('2020-01-01'),
            new DateTimeImmutable('2025-07-01'),
            null,
        );

        self::assertTrue($rate->appliesAt(new DateTimeImmutable('2025-06-30')));
        // Half-open: the end instant belongs to the successor, so the two
        // rates never both answer on the boundary.
        self::assertFalse($rate->appliesAt(new DateTimeImmutable('2025-07-01')));
        self::assertFalse($rate->appliesAt(new DateTimeImmutable('2019-12-31')));
    }

    public function testAnOpenEndedRateHasNoEnd(): void
    {
        $rate = new TaxRate(
            'rate',
            'FR',
            TaxRate::STANDARD,
            2000,
            new DateTimeImmutable('2020-01-01'),
            null,
            null,
        );

        // Open-ended means "until something ends it", not "until a far-future
        // date somebody invented".
        self::assertTrue($rate->appliesAt(new DateTimeImmutable('2099-01-01')));
    }
}
