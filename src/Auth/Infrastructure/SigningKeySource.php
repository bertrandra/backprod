<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use Firebase\JWT\Key;

/**
 * Supplies the public keys a token is verified against.
 *
 * Separate from the provider because key *delivery* and key *use* have
 * different lifecycles (ADR-014): a pinned key set is enough today, and a
 * JWKS poller with caching and rotation can replace it behind this interface.
 *
 * Lives in Infrastructure, not Domain, because the key type comes from the
 * JWT library — keeping it out of the domain is the point of the port.
 */
interface SigningKeySource
{
    /**
     * @return array<string, Key> keyed by `kid`
     */
    public function keys(): array;
}
