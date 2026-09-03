<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\BadRequestException;

/**
 * Paging parameters read from a query string.
 *
 * Out-of-range is refused rather than clamped: silently serving 200 rows to
 * a client that asked for 5000 looks like success and hides that their paging
 * is wrong — they will page through a fifth of the data and conclude that is
 * all of it.
 */
final class PageRequest
{
    /**
     * A whole number within range, or a refusal.
     *
     * @param array<array-key, mixed> $query
     */
    public static function bounded(array $query, string $name, int $default, int $min, int $max): int
    {
        $value = $query[$name] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => $name, 'requirement' => 'must be a whole number'],
            );
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => $name, 'requirement' => sprintf('must be between %d and %d', $min, $max)],
            );
        }

        return $number;
    }
}
