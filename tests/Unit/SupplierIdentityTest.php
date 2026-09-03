<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Service\SupplierIdentity;
use App\Product\Infrastructure\InMemoryProductRegistry;
use App\Shared\Exceptions\ConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who the invoice says is issuing it.
 *
 * A French invoice must name its issuer; a document without one is not an
 * invoice. So the interesting behaviour here is the refusal: an unconfigured
 * product must fail *before* a number is allocated, because numbering is
 * gapless and a document raised by mistake cannot be deleted, only cancelled
 * and explained.
 */
#[CoversClass(SupplierIdentity::class)]
final class SupplierIdentityTest extends TestCase
{
    private const PRODUCT = 'prod-atlas';

    /**
     * @var array<string, string>
     */
    private const COMPLETE = [
        'legal_name' => 'Atlas SAS',
        'vat_number' => 'FR12345678901',
        'registration_number' => '123 456 789 00012',
        'address_line1' => '1 rue de la Paix',
        'postal_code' => '75002',
        'city' => 'Paris',
        'country_code' => 'FR',
    ];

    public function testAConfiguredSupplierBecomesTheSnapshot(): void
    {
        $snapshot = self::identityFor(self::COMPLETE)->forProduct(self::PRODUCT);

        self::assertSame('Atlas SAS', $snapshot['legal_name']);
        self::assertSame('FR12345678901', $snapshot['vat_number']);
        self::assertSame('75002', $snapshot['postal_code']);
        self::assertSame('FR', $snapshot['country_code']);
        // Present as an explicit null rather than missing, so a renderer
        // reading the snapshot two years from now finds the same keys.
        self::assertArrayHasKey('address_line2', $snapshot);
        self::assertNull($snapshot['address_line2']);
    }

    public function testASupplierNotLiableForVatIsStillAValidIssuer(): void
    {
        $configured = self::COMPLETE;
        unset($configured['vat_number']);

        $snapshot = self::identityFor($configured)->forProduct(self::PRODUCT);

        // A micro-entreprise under the franchise en base has no VAT number
        // and invoices perfectly legally. Requiring one would make the
        // platform unusable for a whole class of French business.
        self::assertNull($snapshot['vat_number']);
        self::assertSame('Atlas SAS', $snapshot['legal_name']);
    }

    public function testTheCountryIsNormalisedToUpperCase(): void
    {
        $configured = self::COMPLETE;
        $configured['country_code'] = 'fr';

        $snapshot = self::identityFor($configured)->forProduct(self::PRODUCT);

        self::assertSame('FR', $snapshot['country_code']);
        self::assertSame('FR', SupplierIdentity::jurisdictionOf($snapshot));
    }

    public function testTheJurisdictionIsTheSuppliersCountry(): void
    {
        // Not the customer's: the invoice is issued under the supplier's VAT
        // regime, and `tax_records.jurisdiction` is what a VAT return is
        // filed against.
        self::assertSame(
            'FR',
            SupplierIdentity::jurisdictionOf(self::identityFor(self::COMPLETE)->forProduct(self::PRODUCT)),
        );
    }

    public function testAnUnconfiguredProductCannotInvoice(): void
    {
        $identity = new SupplierIdentity(new InMemoryProductRegistry());

        $this->expectException(ConflictException::class);
        $identity->forProduct(self::PRODUCT);
    }

    /**
     * @return list<array{string}>
     */
    public static function indispensableFields(): array
    {
        return [['legal_name'], ['country_code']];
    }

    #[DataProvider('indispensableFields')]
    public function testAMissingMandatoryMentionRefusesToInvoice(string $field): void
    {
        $configured = self::COMPLETE;
        unset($configured[$field]);

        $identity = self::identityFor($configured);

        try {
            $identity->forProduct(self::PRODUCT);
        } catch (ConflictException $refusal) {
            self::assertSame('BILLING_NOT_CONFIGURED', $refusal->errorCode());
            // The refusal names what is missing, because the person who has
            // to fix it is an operator reading a log, not the customer.
            self::assertSame(['missing' => [$field]], $refusal->details());

            return;
        }

        self::fail('An invoice was allowed without ' . $field . '.');
    }

    public function testABlankValueCountsAsMissing(): void
    {
        $configured = self::COMPLETE;
        $configured['legal_name'] = '   ';

        $this->expectException(ConflictException::class);
        self::identityFor($configured)->forProduct(self::PRODUCT);
    }

    public function testACountryThatIsNotACountryCodeIsRefused(): void
    {
        $configured = self::COMPLETE;
        $configured['country_code'] = 'France';

        $this->expectException(ConflictException::class);
        self::identityFor($configured)->forProduct(self::PRODUCT);
    }

    /**
     * @param array<string, mixed> $configured
     */
    private static function identityFor(array $configured): SupplierIdentity
    {
        return new SupplierIdentity(new InMemoryProductRegistry(
            configuration: [self::PRODUCT => [SupplierIdentity::CONFIGURATION_KEY => $configured]],
        ));
    }
}
