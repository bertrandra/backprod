<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Who the caller is, according to the identity provider.
 *
 * Deliberately narrow: it answers identity only. It carries no tenant, no
 * product and no roles, because those are decisions this backend makes
 * (§10.6) rather than facts the token may assert. A token claiming a tenant
 * would otherwise become a way to choose one.
 */
final class AuthenticatedIdentity
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email = null,
        /** When the credential stops being accepted, as a unix timestamp; null when it says nothing. */
        public readonly ?int $expiresAt = null,
    ) {
    }
}
