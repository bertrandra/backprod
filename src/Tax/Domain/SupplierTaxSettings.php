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
    ) {
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

        return new self($country, ($tax['oss_registered'] ?? false) === true, $supply);
    }
}
