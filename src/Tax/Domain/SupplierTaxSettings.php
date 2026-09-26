<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * The supplier's own fiscal position, which is configuration and never a
 * guess (§25.3: "tout ce qui relève de la qualification fiscale de
 * l'entreprise elle-même reste une décision humaine, paramétrée").
 *
 * `ossRegistered` decides how a cross-border consumer sale is taxed. It is a
 * stated fact rather than something derived from turnover: crossing the
 * threshold is a dated event that changes the regime of *subsequent* sales
 * and never of previous ones, and tracking that crossing is its own piece of
 * work. Configuring it keeps today's invoices right and leaves the automatic
 * crossing to be added without any of this changing shape.
 */
final class SupplierTaxSettings
{
    public const CONFIGURATION_KEY = 'tax';

    public function __construct(
        public readonly string $countryCode,
        public readonly bool $ossRegistered,
        public readonly string $defaultSupplyType,
        public readonly string $currency,
        /**
         * Whether this supplier is registered for VAT at all (2026-09-26).
         *
         * True for the platform, and configured — a platform selling
         * subscriptions is registered, and a deployment where it is not says
         * so rather than being guessed at. It exists because the platform is
         * no longer the only supplier: an organisation selling a seat to one
         * of its own people is one too, and a company under the
         * small-business threshold charges no VAT on anything it sells.
         *
         * Not the same fact as `ossRegistered`, which is about *where* a
         * cross-border consumer sale is taxed by somebody who charges VAT.
         */
        public readonly bool $vatRegistered = true,
    ) {
    }

    /**
     * An organisation selling a seat to one of its own people (2026-09-26).
     *
     * Its country comes from its billing profile, because that is the address
     * the document is issued from, and whether it charges VAT from its tax
     * profile's `taxablePerson` — the one fact §25.3 says cannot be inferred.
     *
     * **Never registered for the one-stop shop.** OSS is for selling to
     * consumers in other member states; the people an organisation seats are
     * its own members, and a deployment where that stops being true needs the
     * fact configured rather than assumed here.
     *
     * The supply and the currency are the product's: it is the same service,
     * resold, and what it costs is what the offer priced.
     */
    public static function forOrganisation(
        string $countryCode,
        bool $vatRegistered,
        string $defaultSupplyType,
        string $currency,
    ): self {
        return new self(strtoupper($countryCode), false, $defaultSupplyType, strtoupper($currency), $vatRegistered);
    }

    /**
     * @param array<string, mixed> $configuration the product's configuration
     */
    public static function fromConfiguration(array $configuration, string $fallbackCountry): self
    {
        $tax = $configuration[self::CONFIGURATION_KEY] ?? null;
        $tax = is_array($tax) ? $tax : [];

        $country = $tax['country'] ?? null;
        $country = is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) === 1
            ? strtoupper($country)
            : $fallbackCountry;

        $supply = $tax['supply_type'] ?? null;
        $supply = is_string($supply) && SupplyType::isKnown($supply)
            ? $supply
            : SupplyType::DIGITAL_SERVICES;

        // The currency a product sells in is its own, not a constant hidden
        // in a controller. ISO 4217, upper case, as everywhere money is
        // handled.
        $currency = $tax['currency'] ?? null;
        $currency = is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) === 1
            ? strtoupper($currency)
            : 'EUR';

        // Registered unless the configuration says otherwise. The platform
        // has been invoicing with VAT since M6, so absent means what it has
        // always meant; only an explicit `false` changes it.
        return new self(
            $country,
            ($tax['oss_registered'] ?? false) === true,
            $supply,
            $currency,
            ($tax['vat_registered'] ?? true) !== false,
        );
    }

    /**
     * The same settings as the JSON this platform stores them as.
     *
     * The inverse of {@see self::fromConfiguration()}, and here beside it for
     * that reason: the console writes this key and the tax engine reads it, and
     * a writer that spelled `oss_registered` differently would configure
     * nothing while answering that it had.
     *
     * @return array<string, mixed>
     */
    public function toConfiguration(): array
    {
        return [
            'country' => $this->countryCode,
            'oss_registered' => $this->ossRegistered,
            'supply_type' => $this->defaultSupplyType,
            'currency' => $this->currency,
            'vat_registered' => $this->vatRegistered,
        ];
    }
}
