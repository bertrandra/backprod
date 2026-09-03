<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A line derives net, VAT and gross once, at construction.
 *
 * The database enforces gross = net + VAT as a check constraint. This is
 * where that identity is established rather than hoped for: if the three
 * could be passed in independently, the constraint would be an outage
 * waiting for the first call site that computed one of them differently.
 */
#[CoversClass(InvoiceLine::class)]
final class InvoiceLineTest extends TestCase
{
    public function testArithmeticIsDerivedAndConsistent(): void
    {
        $line = InvoiceLine::of(
            1,
            'Atlas Pro (v1) — subscription',
            3,
            Money::of(1999, 'EUR'),
            Money::zero('EUR'),
            2000,
            null,
        );

        self::assertSame(5997, $line->net->minorUnits);
        self::assertSame(1199, $line->vat->minorUnits);
        self::assertSame(7196, $line->gross->minorUnits);
        self::assertSame($line->net->minorUnits + $line->vat->minorUnits, $line->gross->minorUnits);
    }

    public function testDiscountReducesTheTaxableAmountNotTheTax(): void
    {
        $line = InvoiceLine::of(2, 'Discounted', 1, Money::of(10_000, 'EUR'), Money::of(1500, 'EUR'), 2000, null);

        // 100.00 less 15.00 is 85.00 taxable, and VAT is 20% of the 85, not
        // 20% of the 100 with the discount taken off afterwards. Those two
        // orders differ by 3.00 on this line, and by real money on a year of
        // them.
        self::assertSame(8500, $line->net->minorUnits);
        self::assertSame(1700, $line->vat->minorUnits);
        self::assertSame(10_200, $line->gross->minorUnits);
    }

    public function testAZeroRatedLineStillCarriesItsRate(): void
    {
        $line = InvoiceLine::of(1, 'Exempt', 1, Money::of(5000, 'EUR'), Money::zero('EUR'), 0, null);

        self::assertSame(0, $line->vat->minorUnits);
        // Recorded rather than omitted: "taxed at 0%" and "no VAT decision
        // was made" look identical on a document that drops the field, and
        // only one of them is defensible to an inspector.
        self::assertSame(0, $line->vatRateBasisPoints);
        self::assertSame(5000, $line->gross->minorUnits);
    }

    public function testTheOfferVersionIsRecordedForLineageOnly(): void
    {
        $line = InvoiceLine::of(
            1,
            'Atlas Pro (v1) — subscription',
            1,
            Money::of(2900, 'EUR'),
            Money::zero('EUR'),
            2000,
            '8e6f1a5c-0000-4000-8000-000000000001',
        );

        self::assertSame('8e6f1a5c-0000-4000-8000-000000000001', $line->sourceOfferVersionId);
        // The price on the line came from the argument, not from the version
        // it names. That is the §25 rule in one assertion: nothing on an
        // issued invoice is read back through a reference.
        self::assertSame(2900, $line->unitPrice->minorUnits);
    }
}
