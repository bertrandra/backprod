<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * A refresh token as the database knows it: by its hash, where it sits in its
 * sign-in, and whether it is spent (ADR-062).
 *
 * `revoked` and `replacedBy` together say which of three things a spent token
 * is. Replaced: it was rotated, and whether presenting it again is a lost
 * answer or a theft depends on whether its replacement has been used.
 * Revoked with no replacement: somebody ended it on purpose — a sign-out, a
 * password reset, a family revoked — and it opens nothing again. Expired: a
 * tab left open too long, and nothing more.
 *
 * Ages and remaining lifetimes are the database's arithmetic, because the
 * database's clock wrote every timestamp they are measured from.
 */
final class StoredRefreshToken
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $authSubject,
        public readonly ?string $email,
        public readonly bool $revoked,
        public readonly bool $expired,
        public readonly string $familyId = '',
        public readonly ?string $replacedBy = null,
        /** Seconds since it was revoked; null while it is not. */
        public readonly ?int $revokedSecondsAgo = null,
        /** Seconds since the sign-in this token descends from. */
        public readonly int $familyAgeSeconds = 0,
        /** Seconds until it expires; zero or less once it has. */
        public readonly int $expiresInSeconds = 0,
        /** SHA-256 of the token — what a derived replacement is checked against. */
        public readonly string $tokenHash = '',
    ) {
    }

    public function usable(): bool
    {
        return !$this->revoked && !$this->expired;
    }

    /** Spent by a rotation, rather than ended on purpose. */
    public function rotated(): bool
    {
        return $this->revoked && $this->replacedBy !== null;
    }
}
