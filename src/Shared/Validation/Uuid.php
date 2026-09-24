<?php

declare(strict_types=1);

namespace App\Shared\Validation;

/**
 * Whether a string has the shape of a UUID.
 *
 * Here rather than only in `App\Shared\Database`, since 2026-09-24: two
 * different layers ask this question for two different reasons, and only
 * one of them is about a database.
 *
 * An **adapter** asks so that a comparison is answerable at all —
 * PostgreSQL does not shrug at `WHERE id = 'banana'`, it raises "invalid
 * input syntax for type uuid", which surfaces as a driver error and a 500.
 * A **controller** asks so that a malformed id in a request body is a 400
 * naming the field rather than a 500 naming nothing, and `deptrac` will
 * not let a controller reach the persistence helpers to find out — rightly,
 * because that is how a Domain → SQL dependency sneaks back in through a
 * side door.
 *
 * So the answer lives in the kernel, which both may depend on, and
 * `Database\Uuid` defers to it. One regex, two callers, no chance of the
 * two disagreeing about what a uuid looks like.
 *
 * The format is checked rather than parsed: the only question is whether
 * the value could name a row.
 */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
