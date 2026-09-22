<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * The public half of what signs this platform's sessions (ADR-051 §3,
 * milestone E), for anybody who wants to verify one without asking.
 *
 * A product beside the platform can then check a bearer locally — the
 * signature, `iss`, `exp` and its own code in `aud` — with no round trip,
 * and it learns nothing it could sign with. What it does *not* learn this
 * way is whether the person still holds a membership or an entitlement:
 * that is `/me/context`, and a product that decides from the token alone
 * decides from a fact up to an hour old.
 */
interface PublicKeys
{
    /**
     * A JWK set (RFC 7517): `{ keys: [ { kty, crv, kid, x, use, alg } ] }`.
     * Two keys during a rotation, so a token signed just before it still
     * verifies until it expires.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array;
}
