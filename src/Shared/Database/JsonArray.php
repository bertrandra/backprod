<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * Decodes an aggregate a query converted to JSON.
 *
 * Aggregated columns come back as a JSON string, or NULL when the aggregate
 * matched nothing. Both mean "no values" to a caller, so both become an empty
 * list rather than a null a caller has to remember to check.
 */
final class JsonArray
{
    /**
     * @return list<string>
     */
    public static function ofStrings(mixed $encoded): array
    {
        if (!is_string($encoded)) {
            return [];
        }

        $decoded = json_decode($encoded, true);

        if (!is_array($decoded)) {
            return [];
        }

        $values = [];

        foreach ($decoded as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
