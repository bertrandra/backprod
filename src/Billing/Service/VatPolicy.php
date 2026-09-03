<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Product\Domain\ProductRegistry;

/**
 * The VAT rate to apply, in basis points.
 *
 * Rates are per-product configuration keyed by country, so a second product
 * selling under a different entity has its own without any code learning
 * either product's name (§12.1):
 *
 *     {"FR": 2000, "DE": 1900, "default": 0}
 *
 * What this deliberately is *not* is a tax engine. §25.1 is explicit that the
 * correct treatment depends on the nature of the operation, the customer's
 * VAT status and their country — reverse charge on intra-EU B2B, exemptions,
 * distance-selling thresholds. None of that is decided here, and pretending
 * otherwise by inferring it from a country code would produce confidently
 * wrong invoices. A configured rate is applied; anything else is zero, and
 * the invoice records which rate was used so the gap is visible rather than
 * buried.
 */
final class VatPolicy
{
    public const CONFIGURATION_KEY = 'vat_rates';
    public const DEFAULT_KEY = 'default';

    public function __construct(private readonly ProductRegistry $products)
    {
    }

    public function rateFor(string $productId, ?string $countryCode): int
    {
        $configured = $this->products->configuration($productId)[self::CONFIGURATION_KEY] ?? null;

        if (!is_array($configured)) {
            return 0;
        }

        $rate = $countryCode === null ? null : ($configured[strtoupper($countryCode)] ?? null);
        $rate ??= $configured[self::DEFAULT_KEY] ?? null;

        // Only whole basis points. A rate of "20" or 19.6 is a configuration
        // mistake, and coercing it would invoice somebody at a rate nobody
        // chose.
        return is_int($rate) && $rate >= 0 && $rate <= 10_000 ? $rate : 0;
    }
}
