<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\AmountText;
use App\Billing\Domain\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind a printed invoice.
 *
 * Tested here rather than through the PDF because a PDF's streams are
 * compressed: a test that rendered a document and looked for "34,80" in the
 * bytes would pass while finding nothing, which is worse than no test.
 */
#[CoversClass(AmountText::class)]
final class AmountTextTest extends TestCase
{
    private const THIN = "\u{202F}";
    private const NBSP = "\u{00A0}";

    #[DataProvider('amounts')]
    public function testAnAmountIsWrittenAsAHumanReadsIt(int $minorUnits, string $currency, string $expected): void
    {
        self::assertSame($expected, AmountText::money(Money::of($minorUnits, $currency)));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'zero' => [0, 'EUR', '0,00' . self::THIN . 'EUR'];
        yield 'under one unit' => [7, 'EUR', '0,07' . self::THIN . 'EUR'];
        yield 'a trailing zero survives' => [3480, 'EUR', '34,80' . self::THIN . 'EUR'];
        yield 'a leading zero in the cents' => [2905, 'EUR', '29,05' . self::THIN . 'EUR'];
        yield 'thousands are grouped' => [123456789, 'EUR', '1' . self::NBSP . '234' . self::NBSP . '567,89' . self::THIN . 'EUR'];
        yield 'exactly one thousand' => [100000, 'EUR', '1' . self::NBSP . '000,00' . self::THIN . 'EUR'];
        yield 'a credit is negative' => [-3480, 'EUR', '-34,80' . self::THIN . 'EUR'];

        // A zero-decimal currency: 1000 JPY is a thousand yen, not ten.
        yield 'yen has no cents' => [1000, 'JPY', '1' . self::NBSP . '000' . self::THIN . 'JPY'];
        yield 'negative yen' => [-500, 'JPY', '-500' . self::THIN . 'JPY'];

        yield 'the currency is upper-cased' => [100, 'eur', '1,00' . self::THIN . 'EUR'];
    }

    /**
     * The case a float would get wrong. 2 796 823 489,47 has more significant
     * digits than a float carries exactly, so a formatter that divided by 100
     * would print a different last cent.
     */
    public function testALargeTotalKeepsItsLastCent(): void
    {
        self::assertStringEndsWith('489,47' . self::THIN . 'EUR', AmountText::money(Money::of(279682348947, 'EUR')));
    }

    #[DataProvider('rates')]
    public function testARateIsWrittenAsAPercentage(int $basisPoints, string $expected): void
    {
        self::assertSame($expected, AmountText::rate($basisPoints));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function rates(): iterable
    {
        yield 'exempt' => [0, '0' . self::THIN . '%'];
        yield 'the French standard rate' => [2000, '20' . self::THIN . '%'];
        yield 'a half point' => [550, '5,5' . self::THIN . '%'];
        yield 'a quarter point' => [2125, '21,25' . self::THIN . '%'];
        yield 'a whole rate loses no digits' => [2100, '21' . self::THIN . '%'];
        yield 'under one percent' => [55, '0,55' . self::THIN . '%'];
        yield 'a trailing zero goes' => [2050, '20,5' . self::THIN . '%'];
    }

    /**
     * Nothing here may hold a currency symbol, a tag or a quote: what this
     * returns is interpolated into markup, and while the renderer escapes it
     * too, a formatter that emitted markup would be relying on that.
     */
    #[DataProvider('amounts')]
    public function testTheOutputCarriesNoMarkup(int $minorUnits, string $currency, string $expected): void
    {
        $written = AmountText::money(Money::of($minorUnits, $currency));

        self::assertSame($expected, $written);
        self::assertSame($written, strip_tags($written));
        self::assertStringNotContainsString('"', $written);
        self::assertStringNotContainsString('<', $written);
    }
}
