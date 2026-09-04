<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The EU-27 standard rates from §25.3, each loaded with its validity window.
 *
 * This is a **paramétrage seed, not a fiscal authority** — §25.3 says so, and
 * risk R7 tracks it: the table was cross-checked against public sources on
 * 2026-09-03 and must be confirmed against the Commission and the national
 * administrations before production.
 *
 * Every rate is loaded with a window rather than as a current value, which is
 * what makes a correction safe: closing one window and opening another leaves
 * every invoice already raised untouched, whereas overwriting a value would
 * silently restate history the next time anything recomputed.
 *
 * The four that moved recently are seeded as *two* windows each, because that
 * is what the shape is for. An invoice dated before the change must still
 * resolve the old rate — and that is exactly the case a table of current
 * values gets wrong:
 *
 *     Estonia    22 → 24     2025-07-01
 *     Romania    19 → 21     2025-08-01
 *     Slovakia   20 → 23     2025-01-01
 *     Finland    24 → 25.5   2024-09-01
 *
 * `valid_from` is deliberately far enough back to cover the invoices this
 * platform can have raised, and the open window carries NULL rather than a
 * far-future date: a rate is in force until something ends it, and inventing
 * an end date would be inventing a fact.
 */
final class Version20260904041000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed EU-27 standard VAT rates, each with its validity window';
    }

    /**
     * Country → list of [basis points, valid_from, valid_until].
     *
     * @return array<string, list<array{int, string, string|null}>>
     */
    private static function rates(): array
    {
        $since = '2020-01-01';

        return [
            'AT' => [[2000, $since, null]],
            'BE' => [[2100, $since, null]],
            'BG' => [[2000, $since, null]],
            'CY' => [[1900, $since, null]],
            'CZ' => [[2100, $since, null]],
            'DE' => [[1900, $since, null]],
            'DK' => [[2500, $since, null]],
            'EE' => [[2200, $since, '2025-07-01'], [2400, '2025-07-01', null]],
            'ES' => [[2100, $since, null]],
            'FI' => [[2400, $since, '2024-09-01'], [2550, '2024-09-01', null]],
            'FR' => [[2000, $since, null]],
            'GR' => [[2400, $since, null]],
            'HR' => [[2500, $since, null]],
            'HU' => [[2700, $since, null]],
            'IE' => [[2300, $since, null]],
            'IT' => [[2200, $since, null]],
            'LT' => [[2100, $since, null]],
            'LU' => [[1700, $since, null]],
            'LV' => [[2100, $since, null]],
            'MT' => [[1800, $since, null]],
            'NL' => [[2100, $since, null]],
            'PL' => [[2300, $since, null]],
            'PT' => [[2300, $since, null]],
            'RO' => [[1900, $since, '2025-08-01'], [2100, '2025-08-01', null]],
            'SE' => [[2500, $since, null]],
            'SI' => [[2200, $since, null]],
            'SK' => [[2000, $since, '2025-01-01'], [2300, '2025-01-01', null]],
        ];
    }

    public function up(Schema $schema): void
    {
        foreach (self::rates() as $country => $windows) {
            foreach ($windows as [$basisPoints, $from, $until]) {
                $end = $until === null ? 'NULL' : sprintf("TIMESTAMPTZ '%s'", $until);

                $this->addSql(sprintf(
                    "INSERT INTO tax_rates (country_code, rate_kind, basis_points, valid_from, valid_until, source)"
                    . " VALUES ('%s', 'STANDARD', %d, TIMESTAMPTZ '%s', %s, '%s')",
                    $country,
                    $basisPoints,
                    $from,
                    $end,
                    'architecture-v2 §25.3, cross-checked 2026-09-03, pending official confirmation (R7)',
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM tax_rates WHERE rate_kind = 'STANDARD'");
    }
}
