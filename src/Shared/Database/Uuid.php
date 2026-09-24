<?php

declare(strict_types=1);

namespace App\Shared\Database;

use App\Shared\Validation\Uuid as Format;

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
 * database.
 *
 * **The regex itself lives in the kernel** ({@see Format}), since
 * 2026-09-24: a controller validating a request body asks the same
 * question and may not reach this layer — `deptrac` says so, and rightly,
 * because a controller allowed into the persistence helpers is how a
 * Domain → SQL dependency comes back through a side door. This name stays
 * because sixty adapters read better for it.
 */
final class Uuid
{
    public static function isValid(string $value): bool
    {
        return Format::isValid($value);
    }
}
