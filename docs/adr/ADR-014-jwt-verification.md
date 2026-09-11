# ADR-014 — Tokens are verified locally against a configured key set

**Status:** accepted; the *issuance* half is superseded by [ADR-038](ADR-038-this-platform-issues-its-own-sessions.md) — this platform now mints its own tokens, and an external provider is one of two supported ways rather than the only one. Everything below about *verification* still holds, and is what made the change an adapter swap.
**Decides:** D4 in `docs/backend-roadmap.md`
**Relates to:** CLAUDE.md "Provider independence", Architecture V2 §31

## Context

Supabase Auth is the initial identity provider, behind an authentication
abstraction. The backend must establish *who* is calling before it resolves
product or tenant, on every request.

Two ways to establish that:

- **Call the provider** per request to introspect the token.
- **Verify the signature locally** against the provider's public keys.

## Decision

`AuthProvider` is a port in `Auth\Domain` returning an
`AuthenticatedIdentity`. The Supabase adapter verifies RS256 JWTs locally
using `firebase/php-jwt`, against a JWK set supplied by a
`SigningKeySource`.

Verification checks the signature, `exp`, `nbf`, and a configured issuer and
audience. A token failing any of these yields `401 UNAUTHENTICATED` with no
detail about which check failed.

## Rationale

- A network round trip to the identity provider on every request adds
  latency to the hot path and makes the API unavailable whenever the
  provider is unreachable.
- Local verification keeps the domain free of the provider: nothing outside
  `Auth\Infrastructure` references Supabase or the JWT library, so replacing
  the provider is an adapter swap.
- `SigningKeySource` is a port because key delivery and key *use* have
  different lifecycles: a static configured key set is enough now, and a
  JWKS endpoint poller with cache and rotation can be introduced behind the
  same interface.

## Consequences

- **A JWKS-fetching key source is not implemented yet.** `StaticSigningKeySource`
  reads a configured JWK set, which is sufficient for tests and for a pinned
  key, but rotation currently requires a configuration change. An HTTP-backed
  source with caching is required before relying on provider-side rotation.
- Revocation is not immediate: a token stays valid until `exp`. Short token
  lifetimes are therefore an operational requirement, not a preference.
- Clock skew between the issuer and this backend is not currently tolerated;
  if that proves fragile in practice, a small configured leeway is the fix.
