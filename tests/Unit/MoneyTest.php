<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\Money;
use App\Shared\Exceptions\ConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Arithmetic on money, which is the layer everything else on an invoice is
 * built from. If rounding is wrong here it is wrong on every document, and
 * the error is not visible in a two-line test — it is visible in an audit of
 * a year of them.
 */
#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    /**
     * @return list<array{int, int, int}>
     */
    public static function taxCases(): array
    {
        return [
            // Exact: 20% of 10.00 is 2.00, no rounding involved.
            [1000, 2000, 200],
            // 20% of 19.99 is 3.998 — rounds up to 4.00.
            [1999, 2000, 400],
            // France's reduced 5.5%, the rate basis points exist for: 5.5%
            // of 1.00 is 5.5 minor units exactly, and half-up takes it to 6.
            [100, 550, 6],
            // The other side of the same half: 5.5% of 3.00 is 16.5 -> 17.
            [300, 550, 17],
            // Zero rate is zero tax, not "no VAT line".
            [12_345, 0, 0],
            // Nothing taxed at any rate is nothing.
            [0, 2000, 0],
            // A credit line: the half-up applies to the magnitude, so -5.5
            // becomes -6 rather than -5. Symmetry matters, because a credit
            // note must exactly undo the invoice it corrects.
            [-100, 550, -6],
            // A very large amount, to show the arithmetic is integral all
            // the way through: 20% of 10,000,000.00.
            [1_000_000_000, 2000, 200_000_000],
        ];
    }

    #[DataProvider('taxCases')]
    public function testTaxRoundsHalfUpOnTheMagnitude(int $net, int $rate, int $expected): void
    {
        self::assertSame($expected, Money::of($net, 'EUR')->taxedAt($rate)->minorUnits);
    }

    public function testTaxKeepsTheCurrency(): void
    {
        self::assertSame('EUR', Money::of(1000, 'eur')->taxedAt(2000)->currency);
    }

    public function testAmountsAddAndSubtract(): void
    {
        $sum = Money::of(1000, 'EUR')->plus(Money::of(250, 'EUR'));
        self::assertSame(1250, $sum->minorUnits);

        self::assertSame(750, $sum->minus(Money::of(500, 'EUR'))->minorUnits);
    }

    public function testMultiplyingByAQuantityIsExact(): void
    {
        self::assertSame(5997, Money::of(1999, 'EUR')->times(3)->minorUnits);
    }

    public function testMixingCurrenciesIsRefusedRatherThanCoerced(): void
    {
        $this->expectException(ConflictException::class);

        // There is no honest exchange rate here, so there is no result to
        // return. Silently treating 10 USD as 10 EUR is the failure this
        // guard exists to prevent.
        Money::of(1000, 'EUR')->plus(Money::of(1000, 'USD'));
    }

    public function testCurrencyIsNormalisedSoTheSameMoneyCompares(): void
    {
        self::assertSame(
            2000,
            Money::of(1000, 'eur')->plus(Money::of(1000, 'EUR'))->minorUnits,
        );
    }

    public function testNegativeIsAboutTheAmountNotTheCurrency(): void
    {
        self::assertTrue(Money::of(-1, 'EUR')->isNegative());
        self::assertFalse(Money::zero('EUR')->isNegative());
    }
}
