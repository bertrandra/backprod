<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\SupplierDetails;
use App\Product\Domain\ProductRegistry;
use App\Shared\Exceptions\ConflictException;

/**
 * Who is issuing the invoice, per product.
 *
 * The shape lives in {@see SupplierDetails} — which fields there are, which an
 * invoice cannot do without, what a country code looks like — because the
 * console writes the same configuration this reads, and a second copy of that
 * rule would be a second answer to "can this product invoice?".
 *
 * What is left here is the decision this side has to make: **missing
 * configuration refuses to invoice** rather than issuing a document with a
 * blank issuer. An invoice is a legal artefact with a permanent number: a wrong
 * one cannot be edited away, only credited and reissued, so the failure belongs
 * before the number is allocated.
 */
final class SupplierIdentity
{
    public const CONFIGURATION_KEY = SupplierDetails::CONFIGURATION_KEY;

    public function __construct(private readonly ProductRegistry $products)
    {
    }

    /**
     * The snapshot to copy onto an invoice.
     *
     * @return array<string, string|null>
     */
    public function forProduct(string $productId): array
    {
        $details = SupplierDetails::parse(
            $this->products->configuration($productId)[self::CONFIGURATION_KEY] ?? null,
        );

        $missing = $details->missing();

        if ($missing !== []) {
            throw self::unconfigured($missing);
        }

        return $details->snapshot();
    }

    /**
     * The country whose VAT is being charged — the supplier's, since that is
     * the regime the invoice is issued under. `tax_records.jurisdiction`
     * records it so a VAT return can be filed per jurisdiction and per rate.
     *
     * Read from a snapshot the caller already holds rather than fetched
     * again, so the identity on the document and the jurisdiction its tax is
     * filed under cannot come from two different reads.
     *
     * @param array<string, string|null> $supplier
     */
    public static function jurisdictionOf(array $supplier): string
    {
        $country = $supplier['country_code'] ?? null;

        if ($country === null) {
            // Unreachable for a snapshot forProduct produced; a refusal
            // rather than a default, because a guessed jurisdiction files
            // somebody's VAT in the wrong country.
            throw self::unconfigured(['country_code']);
        }

        return $country;
    }

    /**
     * @param list<string> $missing
     */
    private static function unconfigured(array $missing): ConflictException
    {
        return new ConflictException(
            'BILLING_NOT_CONFIGURED',
            'This product has no billing identity configured, so it cannot issue invoices.',
            ['missing' => $missing],
        );
    }
}
