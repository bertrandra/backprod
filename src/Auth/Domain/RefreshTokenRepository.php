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

    public function revoke(string $id, ?string $replacedBy): void;

    /** @return int how many live tokens were revoked */
    public function revokeAllFor(string $userId): int;
}
