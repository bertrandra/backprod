<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Product\Domain\ProductRegistry;
use App\Shared\Exceptions\ConflictException;

/**
 * Who is issuing the invoice, per product.
 *
 * A French invoice must name its issuer: legal name, address, SIREN/SIRET and
 * VAT number are mandatory mentions, not decoration. That identity belongs to
 * the legal entity behind a product, so it is product configuration under the
 * key `billing_supplier` — a second product sold by a second company gets its
 * own without any code learning either product's name (§12.1):
 *
 *     {"legal_name": "…", "vat_number": "FR…", "registration_number": "…",
 *      "address_line1": "…", "postal_code": "…", "city": "…",
 *      "country_code": "FR"}
 *
 * Missing configuration refuses to invoice rather than issuing a document
 * with a blank issuer. An invoice is a legal artefact with a permanent number:
 * a wrong one cannot be edited away, only credited and reissued, so the
 * failure belongs before the number is allocated.
 */
final class SupplierIdentity
{
    public const CONFIGURATION_KEY = 'billing_supplier';

    /**
     * The mentions without which the document is not an invoice. Address and
     * VAT number are recorded when configured and omitted when not, because
     * a supplier not liable for VAT legitimately has no number — but nothing
     * can stand in for who is issuing and from which country.
     */
    private const REQUIRED = ['legal_name', 'country_code'];

    /**
     * @var list<string>
     */
    private const FIELDS = [
        'legal_name',
        'vat_number',
        'registration_number',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country_code',
    ];

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
        $configured = $this->products->configuration($productId)[self::CONFIGURATION_KEY] ?? null;

        if (!is_array($configured)) {
            throw self::unconfigured(self::REQUIRED);
        }

        $snapshot = [];
        $missing = [];

        foreach (self::FIELDS as $field) {
            $value = $configured[$field] ?? null;
            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

            if ($value === null && in_array($field, self::REQUIRED, true)) {
                $missing[] = $field;
            }

            $snapshot[$field] = $value;
        }

        if ($missing !== []) {
            throw self::unconfigured($missing);
        }

        $country = $snapshot['country_code'];

        if (!is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            // Present but not a country code — "France", or a stray comma.
            // Refused rather than normalised, because a jurisdiction nobody
            // chose files somebody's VAT in the wrong country.
            throw self::unconfigured(['country_code']);
        }

        $snapshot['country_code'] = strtoupper($country);

        return $snapshot;
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
