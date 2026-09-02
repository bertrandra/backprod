<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use App\Shared\Exceptions\UnauthenticatedException;

/**
 * Port for the identity provider (CLAUDE.md "Provider independence").
 *
 * The domain depends on this interface; only Auth\Infrastructure knows that
 * Supabase and JWTs exist. Swapping provider is an adapter change.
 */
interface AuthProvider
{
    /**
     * @param string $credential the raw bearer credential, without the scheme
     *
     * @throws UnauthenticatedException when the credential is absent, malformed,
     *                                  expired or fails verification
     */
    public function authenticate(string $credential): AuthenticatedIdentity;
}
