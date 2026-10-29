<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * How every password on this platform is hashed: one algorithm, one cost
 * (2026-10-29).
 *
 * Sign-up hashed at bcrypt's default cost (10), a reset and an invitation at
 * 12, and the hash a sign-in for an unknown address is verified against was
 * at 12 too — so a wrong password for an account created by sign-up answered
 * measurably faster than one for an address with no account, which is the
 * enumeration the equaliser exists to prevent. One constant, read by every
 * writer, and the equaliser derived from it, so the two paths cannot drift
 * apart again. A hash written at another cost is rehashed at the next
 * successful sign-in.
 */
final class PasswordHashing
{
    public const ALGORITHM = PASSWORD_BCRYPT;

    public const COST = 12;

    /** @var array{cost: int} */
    public const OPTIONS = ['cost' => self::COST];

    /**
     * A well-formed bcrypt hash of nothing at {@see COST}, for the timing of
     * a miss: `password_verify` against it costs what a real one costs.
     */
    public static function equaliser(): string
    {
        return sprintf('$2y$%02d$C6UzMDM.H6dfI/f/IKcEe.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::COST);
    }

    public static function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, self::ALGORITHM, self::OPTIONS);
    }

    public static function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM, self::OPTIONS);
    }
}
