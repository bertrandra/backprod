<?php

declare(strict_types=1);

namespace App\Shared\Database;

use DateTimeImmutable;
use Exception;
use RuntimeException;

/**
 * Narrows a database row into typed values.
 *
 * Every adapter has to do this, because a row is `array<string, mixed>` and
 * the domain is not. Doing it in one place means the failure is uniform: a
 * column that is not what the schema says raises, rather than each adapter
 * inventing its own tolerance for a value it did not expect.
 *
 * The exceptions name the column and never the value. A malformed row is
 * still tenant data, and a message that quotes it puts that data in a log.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!is_string($value)) {
            throw self::unexpected($column);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * PDO returns PostgreSQL integers as strings, so both are accepted — but
     * only digits: a numeric-looking float would silently truncate.
     *
     * @param array<string, mixed> $row
     */
    public static function integer(array $row, string $column): int
    {
        $value = self::asInteger($row[$column] ?? null);

        if ($value === null) {
            throw self::unexpected($column);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInteger(array $row, string $column): ?int
    {
        return self::asInteger($row[$column] ?? null);
    }

    /**
     * A double precision column.
     *
     * Unlike an integer, a float that arrives as a string is accepted in any
     * form PHP reads as a number: PostgreSQL can render one as `1e-07`, and
     * a driver that hands that back as text has not made it a different
     * value. `Infinity` and `NaN` are refused — PostgreSQL can store both,
     * and neither survives `json_encode` as anything a client can read.
     *
     * @param array<string, mixed> $row
     */
    public static function float(array $row, string $column): float
    {
        $value = self::asFloat($row[$column] ?? null);

        if ($value === null) {
            throw self::unexpected($column);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableFloat(array $row, string $column): ?float
    {
        return self::asFloat($row[$column] ?? null);
    }

    /**
     * A boolean column. PDO returns these natively, but a driver configured
     * to stringify would send PostgreSQL's own `t`/`f`, so both are read.
     *
     * @param array<string, mixed> $row
     */
    public static function boolean(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return match ($value) {
            't', 'true', '1', 1 => true,
            'f', 'false', '0', 0 => false,
            default => throw self::unexpected($column),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function timestamp(array $row, string $column): DateTimeImmutable
    {
        $value = self::asTimestamp($row[$column] ?? null);

        if ($value === null) {
            throw self::unexpected($column);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableTimestamp(array $row, string $column): ?DateTimeImmutable
    {
        return self::asTimestamp($row[$column] ?? null);
    }

    private static function asInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        // Matched whole rather than trimmed of a sign: `ltrim($v, '-')` would
        // accept "--5" and cast it to 0.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function asFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : null;
        }

        return null;
    }

    private static function asTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private static function unexpected(string $column): RuntimeException
    {
        return new RuntimeException(sprintf('Unexpected value in column "%s".', $column));
    }
}
