<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * Refresh tokens, stored as hashes.
 *
 * Nothing here takes or returns a raw token except as something to hash. A
 * stolen copy of this table gives an attacker no credential to present, which is
 * the same reasoning as never storing a password: the server does not need the
 * secret, only the ability to recognise it.
 */
interface RefreshTokenRepository
{
    /**
     * The first token of a new sign-in: its own family, started now.
     *
     * @return string the new row's id
     */
    public function start(string $userId, #[SensitiveParameter] string $tokenHash, int $lifetimeSeconds): string;

    /**
     * The row for this hash, spent or not.
     *
     * Deliberately not `findUsable`: the service has to be able to tell "no such
     * token" from "a token that was already exchanged", because only the second
     * can be evidence of theft.
     */
    public function find(#[SensitiveParameter] string $tokenHash): ?StoredRefreshToken;

    public function findById(string $id): ?StoredRefreshToken;

    /**
     * Replaces a live token with the one whose hash is given, and says which
     * token replaced it (ADR-062).
     *
     * Serialised on the token's row: of two callers rotating the same token
     * at once, the first writes the replacement and the second finds it
     * written and is told the same id. Since the replacement is derived from
     * the token, both were asking for the same one anyway — the lock only
     * makes sure it is written once.
     *
     * The replacement expires `$lifetimeSeconds` from now, and never later
     * than `$maxAgeSeconds` after the sign-in began.
     *
     * @return string|null the replacing token's id; null when the token was
     *                     ended rather than rotated, has expired, or its
     *                     sign-in is older than `$maxAgeSeconds`
     */
    public function rotate(string $id, #[SensitiveParameter] string $successorHash, int $lifetimeSeconds, int $maxAgeSeconds): ?string;

    /** @return int how many live tokens of this sign-in were revoked */
    public function revokeFamily(string $familyId): int;

    /** @return int how many live tokens were revoked, across every sign-in */
    public function revokeAllFor(string $userId): int;
}
