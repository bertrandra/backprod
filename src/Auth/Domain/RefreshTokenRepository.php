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
    /** @return string the new row's id */
    public function issue(string $userId, #[SensitiveParameter] string $tokenHash, int $lifetimeSeconds): string;

    /**
     * The row for this hash, spent or not.
     *
     * Deliberately not `findUsable`: the service has to be able to tell "no such
     * token" from "a token that was already exchanged", because only the second
     * is evidence of theft.
     */
    public function find(#[SensitiveParameter] string $tokenHash): ?StoredRefreshToken;

    /**
     * Ends one token, and says whether this call is what ended it.
     *
     * The boolean is what makes a revocation a *claim* rather than a request:
     * a token is revoked once, so of two callers racing to end the same one
     * exactly one is told true. Refreshing needs that to hand a chain over
     * without forking it.
     *
     * @return bool false when the token was already spent, and this call
     *              changed nothing
     */
    public function revoke(string $id, ?string $replacedBy): bool;

    /**
     * The live token at the end of the chain this one was rotated into, when
     * it was rotated away less than `$withinSeconds` ago.
     *
     * Null for everything else: a token nobody replaced, a chain whose end is
     * spent or expired, and — the case this exists to bound — a token rotated
     * away longer ago than that. Why there is a window at all is the calling
     * service's decision; the window is *compared* here because the database
     * owns the clock that wrote `revoked_at`, as it owns the one that decides
     * whether a token has expired.
     */
    public function liveEndOfChainAfter(string $rotatedId, int $withinSeconds): ?string;

    /** @return int how many live tokens were revoked */
    public function revokeAllFor(string $userId): int;
}
