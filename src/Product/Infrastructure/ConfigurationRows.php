<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

/**
 * `product_configuration` rows, decoded.
 *
 * Shared by the two ports that read that table — {@see PostgresProductRegistry}
 * for a tenant's own product and {@see PostgresProductSettings} for the console
 * — because the column is JSONB and comes back as text, and two copies of that
 * `json_decode` would be two places to disagree about what a malformed row
 * means.
 */
final class ConfigurationRows
{
    /**
     * @param list<array<string, mixed>> $rows each with `key` and `value`
     *
     * @return array<string, mixed>
     */
    public static function decode(array $rows): array
    {
        $configuration = [];

        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;

            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            // Stored as JSONB, so the column comes back as JSON text.
            $configuration[$key] = json_decode($value, true);
        }

        return $configuration;
    }
}
