<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * Membership of the EU VAT area, which is what decides whether a sale can be
 * intra-Community at all.
 *
 * A list rather than a rate table on purpose: rates live in `tax_rates` with
 * their windows, because they move. Membership moves too, but by treaty and
 * rarely, and when it does the correct response is a migration and an ADR —
 * not a silently different answer from a configuration file.
 *
 * `XI` is deliberately present: Northern Ireland follows EU rules for goods
 * while sitting inside GB, so a supply to XI can be intra-Community even
 * though XI is not a member state and not an ISO country code.
 */
final class EuMemberStates
{
    private const MEMBERS = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
        'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function includes(?string $countryCode): bool
    {
        if ($countryCode === null) {
            return false;
        }

        return in_array(strtoupper($countryCode), self::MEMBERS, true);
    }

    /**
     * Whether the VAT rules of the Union govern a supply to this territory.
     * Wider than membership, because of Northern Ireland.
     */
    public static function vatRulesApplyTo(?string $countryCode): bool
    {
        return self::includes($countryCode) || strtoupper((string) $countryCode) === 'XI';
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::MEMBERS;
    }
}
