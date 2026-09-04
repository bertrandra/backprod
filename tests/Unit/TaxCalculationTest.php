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
