<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Who is issuing an invoice, as a shape rather than as a read.
 *
 * A French invoice must name its issuer: legal name, address, SIREN/SIRET and
 * VAT number are mandatory mentions and not decoration. That identity belongs
 * to the legal entity behind a product, so it lives in product configuration
 * under {@see self::CONFIGURATION_KEY} — a second product sold by a second
 * company gets its own without any code learning either product's name
 * (§12.1).
 *
 * **This class exists because two callers needed the same rule and would
 * otherwise each have written it.** {@see \App\Billing\Service\SupplierIdentity}
 * reads it to stamp an invoice and refuses to issue one when it is incomplete;
 * the console writes it and has to refuse an incomplete submission at the
 * boundary. The rule — which fields there are, which of them an invoice cannot
 * do without, and what a country code looks like — is stated once, here.
 *
 * **Nothing here throws.** Normalising and knowing what is missing are facts
 * about the values; what to *do* about a missing `legal_name` differs between
 * the two callers (a conflict for something already configured, a validation
 * failure for something being submitted), and that decision is theirs.
 */
final class SupplierDetails
{
    public const CONFIGURATION_KEY = 'billing_supplier';

    /**
     * Every mention this platform records. Address and VAT number are kept
     * when configured and omitted when not, because a supplier not liable for
     * VAT legitimately has no number.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'legal_name',
        'vat_number',
        'registration_number',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country_code',
    ];

    /**
     * The mentions without which the document is not an invoice. Nothing can
     * stand in for who is issuing and from which country: the country decides
     * the VAT regime the document is issued under, so a guess files somebody's
     * VAT in the wrong jurisdiction.
     *
     * @var list<string>
     */
    public const REQUIRED = ['legal_name', 'country_code'];

    /**
     * @param array<string, string|null> $fields normalised, one entry per
     *                                           {@see self::FIELDS}
     */
    private function __construct(private readonly array $fields)
    {
    }

    /**
     * Reads whatever was stored or submitted, keeping what is usable.
     *
     * Anything that is not a JSON object is an empty identity rather than an
     * error: configuration absent and configuration malformed are the same
     * situation for an invoice, and {@see self::missing()} says so either way.
     */
    public static function parse(mixed $values): self
    {
        $values = is_array($values) ? $values : [];
        $fields = [];

        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            $fields[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        $country = $fields['country_code'];

        // Upper-cased here so `fr` and `FR` are one jurisdiction rather than
        // two. A value that is present but not a country code — "France", or a
        // stray comma — is left alone and reported by missing(), never
        // normalised into something nobody chose.
        if ($country !== null && preg_match('/^[A-Za-z]{2}$/', $country) === 1) {
            $fields['country_code'] = strtoupper($country);
        }

        return new self($fields);
    }

    /**
     * The fields an invoice cannot be issued without, and has not got.
     *
     * `country_code` appears here when it is present but is not a two-letter
     * code, because for this purpose an unusable value and an absent one are
     * the same: neither names a jurisdiction.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        foreach (self::REQUIRED as $field) {
            if ($this->fields[$field] === null) {
                $missing[] = $field;
            }
        }

        $country = $this->fields['country_code'];

        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $missing[] = 'country_code';
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The snapshot to copy onto an invoice, or to store as configuration.
     *
     * One entry per field, null included: a document whose issuer had no VAT
     * number should say so by carrying null, not by the key being absent and
     * every reader having to decide what that meant.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return $this->fields;
    }

    /**
     * The country whose VAT this issuer charges, or null when it has none
     * worth trusting.
     *
     * Callers that have checked {@see self::isComplete()} get a string; the
     * null is what makes "guess a jurisdiction" impossible to write by
     * accident.
     */
    public function countryCode(): ?string
    {
        return in_array('country_code', $this->missing(), true) ? null : $this->fields['country_code'];
    }
}
