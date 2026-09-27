<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\BadRequestException;

/**
 * The filters above a list (2026-09-27): whose documents, and in which state.
 *
 * Read from the query string and refused when malformed, the way
 * {@see PageRequest} refuses a page that is not a number — never silently
 * ignored, because a filter that did nothing would answer a different
 * question from the one on screen.
 *
 * **A person filter narrows; it never widens.** It is applied *beside* the
 * caller's own scope, so a member naming a colleague gets an empty page and
 * not the colleague's invoices — the scope comes from the context, and a
 * parameter can only ask for less.
 */
final class ListFilter
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * The person the list is narrowed to, or null for everybody the caller may see.
     *
     * @param array<array-key, mixed> $query
     */
    public static function person(array $query, string $name = 'person'): ?string
    {
        $value = $query[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || preg_match(self::UUID, $value) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => $name, 'requirement' => 'must be a user id'],
            );
        }

        return strtolower($value);
    }

    /**
     * One of the statuses the list's documents can be in, or null for all.
     *
     * @param array<array-key, mixed> $query
     * @param list<string>            $allowed
     */
    public static function status(array $query, array $allowed, string $name = 'status'): ?string
    {
        $value = $query[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => $name, 'requirement' => 'must be one of ' . implode(', ', $allowed)],
            );
        }

        return $value;
    }
}
