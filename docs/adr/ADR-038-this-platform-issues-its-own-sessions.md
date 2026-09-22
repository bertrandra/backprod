# ADR-038 — This platform issues its own sessions

**Status:** accepted; the algorithm amended by ADR-051 milestone E (2026-09-21):
the access token is signed **EdDSA** with an Ed25519 pair derived from
`AUTH_SIGNING_SECRET`, and the public half is served at `GET /api/v1/auth/jwks`.
"HS256, not RS256" below was right while the issuer and the verifier were one
process; a product beside the platform is a second verifier, and under HS256
the only way to let it verify is to hand it the mint. Everything else here —
the routes, the cookie, rotation of the refresh token, the 32-character floor,
the 503 — is unchanged, and so is the secret: nothing new is generated or stored,
so a rotation is still an edit to `.env` (`AUTH_SIGNING_SECRET_PREVIOUS` keeps
the old key verifying for an hour).
**Supersedes:** the issuance half of
[ADR-014](ADR-014-jwt-verification.md) — verification is
unchanged, and an external provider remains supported
**Relates to:** Architecture V2 §10.6, §31; CLAUDE.md "Provider independence";
[deploying-to-siteground.md](../deploying-to-siteground.md)

## Context

ADR-014 gave identity to Supabase: the browser obtained a token, this platform
verified its signature and never issued one. That was right for a platform with
no login screen and one less secret to hold, and it made `AuthProvider` a port
from the beginning — which is why this decision is an adapter and a migration
rather than a rewrite.

Two things changed it.

**The deployment target.** The platform is distributed as a bundle for a host
offering PHP, PostgreSQL and JavaScript. Every part of it ran inside those three
except one: getting a token. That single dependency brought a second vendor, two
public values compiled into the bundle, a third-party origin in the
Content-Security-Policy, and a rebuild whenever any of them changed.

**Where the browser had to keep the refresh token.** With an external issuer, the
page holds the credential, and a page has nowhere safe to hold one: `localStorage`,
`sessionStorage` and a readable cookie are all reachable by injected script. U11
shipped it in `localStorage` and wrote the exposure into three documents as the
first thing to fix. The fix was never a better storage choice — it was for the
*server* to own the exchange, because only the server can set a cookie the page
cannot read.

## Decision

**PHP issues an HS256 access token and a rotating refresh token.**
`firebase/php-jwt` was already a dependency for verification; issuing is the same
library. Three public routes — `POST /api/v1/auth/token`, `/refresh`, `/sign-out` —
and they are ordinary operations in `openapi.json`, which is what removes the
second HTTP door from the frontend: U11 needed `src/api/auth.ts` because Supabase's
endpoint could never be in the contract. It is deleted, and §8.1's "one module
reaches the API" is a rule again rather than a rule with an exception.

**HS256, not RS256.** An asymmetric pair exists so a verifier need not be trusted
to sign — which mattered when the issuer and the verifier were different systems.
They are the same process now, so a public key would protect nothing and would
cost key generation, key storage and a rotation story on a host whose secret
management is a `.env` file. The secret must be at least 32 bytes; the library
refuses less, and the platform says so with a 503 rather than letting the
`DomainException` reach an operator as a 500.

**The access token in the body, the refresh token in an `HttpOnly` cookie.** Two
credentials, two lifetimes, two homes:

| | Access token | Refresh token |
|---|---|---|
| Lives | in memory, in the page | in a cookie the page cannot read |
| Lasts | one hour | thirty days |
| Sent to | every API route, as a bearer token | `/api/v1/auth` only, by the browser |
| Revocable | no — it expires | yes, and rotation makes reuse detectable |

`SameSite=Strict` is also the CSRF answer for those three routes: a cross-site POST
carries no cookie, so there is nothing to forge. Nothing else in the platform is
cookie-authenticated, so the usual objection — that Strict breaks incoming links —
does not apply, because no link ever needs this cookie.

**Rotation, with reuse treated as theft.** Every refresh revokes the token it was
given and records which one replaced it. Presenting an already-revoked token is
therefore a detectable event, and the response is to revoke **every** session for
that account. The two possibilities — a client that replayed, or a copy in somebody
else's hands — are indistinguishable from the server, and their costs are not
symmetric: signing the real person out costs them a sign-in, and leaving a thief
signed in costs them everything.

**Credentials in their own table, and the invariants in the database.**
`local_credentials` rather than columns on `users`, because `users` is
provider-agnostic and erasure must be able to drop a credential without touching
§15's retention constraints. Two CHECKs carry the rules that matter:
`password_hash LIKE '$%'`, so a plaintext password cannot be stored by any route,
and `token_hash ~ '^[0-9a-f]{64}$'`, so a raw refresh token cannot either. Both are
where "invariants belong in the database" says they belong: somewhere no code path
can route around.

## Consequences

**A bundle needs no configuration.** No keys are compiled into the JavaScript,
`connect-src` is `'self'`, and `vite build` takes no mode and no environment. One
bundle works against any deployment; the only thing an operator sets is `.env`.

**The XSS caveat is gone**, and with it three paragraphs of documentation that
existed to admit it. An injected script can still act as the person while the page
is open — no design prevents that — but it cannot take a credential away with it.

**Nothing external is needed at all**, which was the point. R1 in
`docs/backend-roadmap.md` settled long before this ADR that SiteGround hosts
PostgreSQL on the same account as PHP; what this ADR removes is the one thing
that had since crept back in on the identity side. A host offering PHP,
PostgreSQL and JavaScript can now run the whole platform with nothing beyond
its own account.

**What this does not do.** No password reset, no email verification, no
registration endpoint, and no second factor. A person's credential is set by
`demo:seed` or by SQL, which is honest for a platform whose tenants are created by
an operator and dishonest the moment anybody self-registers. Those are the next
decisions, and none of them changes the shape above.

**An external provider still works.** `SUPABASE_JWKS` and `SUPABASE_ISSUER`
configure ADR-014's path unchanged, and `AUTH_SIGNING_SECRET` wins when both are
set — a deployment given its own signing secret has been configured to be
self-contained, and silently preferring the remote provider would make that
setting a no-op nobody could see.

**A timing oracle is argued, not proven.** `Sessions::signIn` verifies a password
against a fixed hash when no account matches, so "no such address" and "wrong
password" take the same time. Deliberately breaking that changed no test, because
a stopwatch difference is not observable in a functional suite. It is recorded here
as reasoning rather than claimed as verified.
