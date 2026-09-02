# ADR-015 — Tenant is derived from membership, never from the client

**Status:** accepted
**Decides:** D5 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §12, §31; CLAUDE.md "Multi-tenancy and security"

## Context

Tenant isolation is a security boundary. CLAUDE.md is unambiguous: never
trust a client-provided tenant id; the backend derives tenant context from
authenticated identity and authorisation.

But §12 supports both B2C (one user, one tenant) and B2B, where a user may
belong to several tenants — and in a multi-product platform, membership is
per product. So "derive it" alone does not settle what happens when the
derivation yields more than one answer.

## Decision

The backend loads the authenticated user's memberships **for the resolved
product** and then:

| Memberships | Outcome |
|---|---|
| 0 | `403 NO_TENANT_ACCESS` |
| 1 | that tenant is the context |
| >1 | client must name one with `X-Tenant`, and it must be among the loaded memberships; otherwise `409 TENANT_SELECTION_REQUIRED` |

The tenant id is only ever taken from the membership record. `X-Tenant`
selects *among already-authorised* tenants; a value that is not a loaded
membership is rejected exactly as an unknown tenant would be.

## Rationale

Selecting among verified memberships is not trusting the client. The
authorisation decision is made from server-side membership data; the header
only disambiguates between outcomes the user is already entitled to. A
request naming a tenant the user does not belong to is indistinguishable, in
its result, from one naming a tenant that does not exist.

Silently picking the first of several memberships was rejected: it would make
which tenant a write lands in depend on row order.

## Consequences

- A body or query parameter named `tenant_id` is never read. Only the
  membership lookup, optionally narrowed by `X-Tenant`, determines the tenant.
- The 409 is actionable: the response lists the tenants the user may choose,
  since those are already known to them.
- Membership lookup happens on every protected request and is therefore a
  caching candidate once real persistence lands in M2.
