# ADR-017 — Platform users are provisioned on first authenticated request

**Status:** accepted
**Relates to:** ADR-014, Architecture V2 §12, §26.1

## Context

The identity provider owns sign-up: a person exists in Supabase before this
backend ever sees them. But the platform needs its own `users` row, because
`tenant_members` and every future audit record must reference a stable local
identifier — not a provider subject that changes if the provider does.

So something has to create that row. The options:

- **Provider webhook** — Supabase calls us on sign-up.
- **Admin provisioning** — a person is created here before they can sign in.
- **Just-in-time** — the row is created on the first authenticated request.

## Decision

A `UserDirectory` port maps an `AuthenticatedIdentity` to a platform user,
creating the row when the provider subject is seen for the first time. The
resulting **internal user id**, not the provider subject, is what
`RequestContext` carries and what every foreign key references.

The write is an idempotent upsert on `auth_subject`, so concurrent first
requests from the same person cannot produce two rows or a failure.

## Rationale

A webhook makes sign-in depend on a delivery that may be late, retried or
lost — a person who signed up successfully would get an unexplained 403 until
it arrived. Admin provisioning cannot work at all while self-service sign-up
exists.

Just-in-time keeps the provider authoritative for *who exists* and this
backend authoritative for *what they may do*, with no coupling between the
two beyond the subject claim.

Provisioning a user grants nothing: a brand-new user has no tenant
membership and is refused with `403 NO_TENANT_ACCESS` (ADR-015) until
someone invites them. Creating the row is bookkeeping, not authorisation.

## Consequences

- A read-shaped request can perform a write on first sight. It happens once
  per user and is an upsert, but it means the identity path needs a writable
  connection.
- The subject is stored, so a provider migration is a data migration of
  `auth_subject` rather than a rewrite of every foreign key.
- Email is copied from the token as a convenience for display and is
  refreshed on later sign-ins; it is not a key, since providers allow it to
  change. Under §26.1 it is personal data, and it is held because
  identifying a member in a tenant's member list requires it.
