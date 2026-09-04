<?php

declare(strict_types=1);

namespace App\Tax\Domain;

use DateTimeImmutable;

/**
 * A VAT number, and — separately — whether it was verified (§25.3).
 *
 * The separation is the whole point. "The customer typed a number" and "the
 * number was checked against VIES" are different facts, and only the second
 * grants reverse charge. A model with one field for both invoices intra-EU
 * B2B at zero on nothing more than a well-formed string, and leaves the
 * supplier liable for the tax (risk R8).
 *
 * `countryPrefix` is not an ISO country code, and conflating them is a
 * structural bug rather than a cosmetic one: Greece is `GR` as a country and
 * `EL` on a number, and Northern Ireland uses `XI`, which is not a country
 * code at all.
 */
final class TaxIdentification
{
    public const UNVERIFIED = 'UNVERIFIED';
    public const VERIFIED = 'VERIFIED';
    public const INVALID = 'INVALID';

    /**
     * VIES could not be reached. Deliberately its own status rather than
     * folded into UNVERIFIED: "we asked and got no answer" is a different
     * operational fact from "nobody has asked", and §25.2 has to be able to
     * show the difference. Neither grants reverse charge.
     */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * @param array<string, mixed>|null $verificationResult
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $vatNumber,
        public readonly string $countryPrefix,
        public readonly string $status,
        public readonly ?DateTimeImmutable $verifiedAt,
        public readonly ?string $verificationSource,
        public readonly ?array $verificationResult,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /**
     * The status a fiscal fact records for this customer (§25.3).
     *
     * A number that exists but was not proved is `PRESENTED`, never
     * `VERIFIED` — and the database refuses reverse charge on anything but
     * the latter, so the distinction survives a caller that forgets it.
     */
    public function transactionStatus(): string
    {
        return $this->isVerified() ? 'VERIFIED' : 'PRESENTED';
    }

    /**
     * Greece's ISO code is GR and its VAT prefix is EL; Northern Ireland's
     * XI prefix has no ISO country. This maps a prefix back to the country
     * it taxes in, which is what a rate lookup needs.
     */
    public static function countryForPrefix(string $prefix): string
    {
        return match (strtoupper($prefix)) {
            'EL' => 'GR',
            // XI is Northern Ireland, which for goods follows EU rules while
            // sitting inside GB. It is returned as itself: no ISO country
            // describes it, and pretending it is GB would tax it wrongly.
            'XI' => 'XI',
            default => strtoupper($prefix),
        };
    }

    /**
     * And the inverse, for building a number from a country.
     */
    public static function prefixForCountry(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'GR' => 'EL',
            default => strtoupper($countryCode),
        };
    }
}
