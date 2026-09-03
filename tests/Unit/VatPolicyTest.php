<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Service\VatPolicy;
use App\Product\Infrastructure\InMemoryProductRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which VAT rate is applied, and what happens when the configuration is
 * wrong.
 *
 * The rate is per-product configuration, which means somebody types it. Every
 * mistyped shape has to resolve to zero rather than to something plausible:
 * invoicing a customer at a rate nobody chose is worse than under-charging
 * visibly, because the first is discovered by an inspector and the second by
 * an accountant.
 */
#[CoversClass(VatPolicy::class)]
final class VatPolicyTest extends TestCase
{
    private const PRODUCT = 'prod-atlas';

    public function testAConfiguredCountryRateIsUsed(): void
    {
        self::assertSame(2000, self::policyFor(['FR' => 2000, 'DE' => 1900])->rateFor(self::PRODUCT, 'FR'));
        self::assertSame(1900, self::policyFor(['FR' => 2000, 'DE' => 1900])->rateFor(self::PRODUCT, 'DE'));
    }

    public function testCountryCodesAreMatchedCaseInsensitively(): void
    {
        // A profile stores "FR"; a caller may well pass "fr". Failing to
        // match would silently zero-rate a French customer.
        self::assertSame(2000, self::policyFor(['FR' => 2000])->rateFor(self::PRODUCT, 'fr'));
    }

    public function testAnUnlistedCountryFallsBackToTheDefault(): void
    {
        $policy = self::policyFor(['FR' => 2000, 'default' => 0]);

        self::assertSame(0, $policy->rateFor(self::PRODUCT, 'US'));
    }

    public function testACustomerWithNoCountryGetsTheDefault(): void
    {
        // A profile without a country is incomplete, not exempt — but this
        // is not the place that decides which. The default applies and the
        // rate is recorded on the invoice, so the gap is visible.
        self::assertSame(1000, self::policyFor(['default' => 1000])->rateFor(self::PRODUCT, null));
    }

    public function testAProductWithNoRatesConfiguredChargesNothing(): void
    {
        $policy = new VatPolicy(new InMemoryProductRegistry());

        self::assertSame(0, $policy->rateFor(self::PRODUCT, 'FR'));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function unusableRates(): array
    {
        return [
            // A percentage where basis points were meant. Accepting it would
            // invoice at 0.2% instead of 20%.
            ['20'],
            [20.0],
            // Beyond any real rate, so almost certainly a units mistake.
            [10_001],
            // Negative tax is not a thing.
            [-2000],
            [null],
            [true],
            [['FR' => 2000]],
        ];
    }

    #[DataProvider('unusableRates')]
    public function testAnUnusableRateIsZeroRatherThanCoerced(mixed $rate): void
    {
        self::assertSame(0, self::policyFor(['FR' => $rate])->rateFor(self::PRODUCT, 'FR'));
    }

    public function testConfigurationOfTheWrongShapeChargesNothing(): void
    {
        $policy = new VatPolicy(new InMemoryProductRegistry(
            configuration: [self::PRODUCT => [VatPolicy::CONFIGURATION_KEY => 'twenty percent']],
        ));

        self::assertSame(0, $policy->rateFor(self::PRODUCT, 'FR'));
    }

    /**
     * @param array<string, mixed> $rates
     */
    private static function policyFor(array $rates): VatPolicy
    {
        return new VatPolicy(new InMemoryProductRegistry(
            configuration: [self::PRODUCT => [VatPolicy::CONFIGURATION_KEY => $rates]],
        ));
    }
}
