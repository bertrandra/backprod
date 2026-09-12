<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\SupplierDetails;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape of an invoice's issuer, which two callers share.
 *
 * {@see \App\Billing\Service\SupplierIdentity} reads it to stamp a document and
 * refuses to issue one when it is incomplete; the console writes it and refuses
 * an incomplete submission. What is asserted here is that there is **one** answer
 * to "can this product invoice?", because two would eventually differ — with a
 * console reporting ready where a checkout refuses.
 */
#[CoversClass(SupplierDetails::class)]
final class SupplierDetailsTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const COMPLETE = [
        'legal_name' => 'Atlas SAS',
        'vat_number' => 'FR12345678901',
        'country_code' => 'FR',
    ];

    public function testTheSnapshotCarriesEveryFieldIncludingTheAbsentOnes(): void
    {
        $snapshot = SupplierDetails::parse(self::COMPLETE)->snapshot();

        foreach (SupplierDetails::FIELDS as $field) {
            self::assertArrayHasKey($field, $snapshot);
        }

        // Absent as an explicit null rather than as a missing key: a renderer
        // reading a two-year-old snapshot must find the same shape it does now.
        self::assertNull($snapshot['city']);
        self::assertSame('Atlas SAS', $snapshot['legal_name']);
    }

    public function testACompleteIdentityIsComplete(): void
    {
        $details = SupplierDetails::parse(self::COMPLETE);

        self::assertSame([], $details->missing());
        self::assertTrue($details->isComplete());
        self::assertSame('FR', $details->countryCode());
    }

    public function testASupplierNotLiableForVatIsStillAValidIssuer(): void
    {
        $configured = self::COMPLETE;
        unset($configured['vat_number']);

        // A micro-entreprise under the franchise en base has no VAT number and
        // invoices perfectly legally.
        self::assertTrue(SupplierDetails::parse($configured)->isComplete());
    }

    /**
     * @return list<array{string}>
     */
    public static function indispensableFields(): array
    {
        return [['legal_name'], ['country_code']];
    }

    #[DataProvider('indispensableFields')]
    public function testAnAbsentMandatoryMentionIsMissing(string $field): void
    {
        $configured = self::COMPLETE;
        unset($configured[$field]);

        self::assertSame([$field], SupplierDetails::parse($configured)->missing());
    }

    #[DataProvider('indispensableFields')]
    public function testABlankMandatoryMentionIsMissingToo(string $field): void
    {
        $configured = self::COMPLETE;
        $configured[$field] = '   ';

        // Blank and absent are the same omission. A screen sending an empty box
        // has said "there is none", not "there is one and it is whitespace".
        self::assertSame([$field], SupplierDetails::parse($configured)->missing());
    }

    public function testTheCountryIsUpperCasedSoOneJurisdictionIsOneJurisdiction(): void
    {
        $configured = self::COMPLETE;
        $configured['country_code'] = 'fr';

        self::assertSame('FR', SupplierDetails::parse($configured)->countryCode());
    }

    public function testACountryThatIsNotACountryCodeIsMissingRatherThanNormalised(): void
    {
        $configured = self::COMPLETE;
        $configured['country_code'] = 'France';

        $details = SupplierDetails::parse($configured);

        // Reported once, not twice: it is present, so the required-field loop
        // passes it, and the code check is what names it.
        self::assertSame(['country_code'], $details->missing());
        // And never guessed into something nobody chose — a jurisdiction
        // invented here files somebody's VAT in the wrong country.
        self::assertNull($details->countryCode());
        self::assertSame('France', $details->snapshot()['country_code']);
    }

    public function testConfigurationThatIsNotAnObjectIsAnEmptyIdentity(): void
    {
        // Absent and malformed are the same situation for an invoice, so this
        // reports the same missing mentions rather than raising — the caller
        // decides what a refusal looks like.
        foreach ([null, 'billing_supplier', 42, []] as $nonsense) {
            self::assertSame(
                SupplierDetails::REQUIRED,
                SupplierDetails::parse($nonsense)->missing(),
                'parsing ' . get_debug_type($nonsense),
            );
        }
    }

    public function testValuesAreTrimmedBecauseAStraySpaceIsNotPartOfALegalName(): void
    {
        self::assertSame(
            'Atlas SAS',
            SupplierDetails::parse(['legal_name' => '  Atlas SAS  ', 'country_code' => 'FR'])
                ->snapshot()['legal_name'],
        );
    }

    public function testANonStringValueIsIgnoredRatherThanCast(): void
    {
        // A client sending a number as a legal name has a bug; casting it to
        // "42" would print that bug on an invoice.
        $details = SupplierDetails::parse(['legal_name' => 42, 'country_code' => 'FR']);

        self::assertNull($details->snapshot()['legal_name']);
        self::assertSame(['legal_name'], $details->missing());
    }
}
