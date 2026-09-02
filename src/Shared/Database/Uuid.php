<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * Whether a string can be compared against a UUID column at all.
 *
 * PostgreSQL does not shrug at `WHERE id = 'banana'` — it raises "invalid
 * input syntax for type uuid", which surfaces as a driver error and a 500.
 * A caller who mistyped an id in a URL deserves the same 404 as one who
 * guessed a well-formed id that does not exist; anything else turns the
 * shape of an id into an oracle and litters the logs with other people's
 * typos.
 *
 * So adapters check first and report "not found" without asking the
 * database. The format is checked rather than parsed: the only question is
 * whether the comparison is answerable.
 */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
