# ADR-020 — Entitlements are stored rows with a validity window

**Status:** accepted
**Decides:** how §12's chain ends, for M5 part 2 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §12, §13, §10.6, non-negotiable #18

## Context

§12's chain is Offer → Subscription → Entitlements → Tenant. Part 1 built the
offer. This decides how the last arrow works: given that a tenant subscribed
to a particular offer version, how does the platform answer "may they use
this?" — a question the §10.6 context chain asks on **every authenticated
request**.

§13 requires the answer to be about entitlements, never plan names. Beyond
that the shape is open, and two obvious shapes are both wrong in different
ways.

## Decision

- **Entitlements are stored rows**, written when a subscription changes, not
  derived by joining through the catalogue on every request.
- **Each row carries a validity window**, so it lapses on the clock.
- **Rows carry a `source`**: `SUBSCRIPTION` or `OVERRIDE`. A subscription
  change rewrites the first and leaves the second.
- **Where a feature is held twice, the most generous wins.**
- **Quotas are enforced against measured usage**, through a `UsageSource` port
  implemented by whichever module owns the thing being counted.
- **An unmetered quota is not enforced, and says so.**
- **Absence grants nothing**, including absence of a line in an offer.

## Rationale

### Why not derive on every request

The honest alternative is a view: join `subscriptions` → `offer_versions` →
`offer_version_features` → `features` and filter by the subscription's period.
It has one real advantage — nothing can drift, because there is nothing to
drift from.

It is rejected because of where it runs. `capabilitiesFor()` is called during
the §10.6 chain, before any handler sees the request, on every authenticated
call in the platform. Putting a four-table join on that path makes the
commerce schema a hard dependency of every request, and makes every future
change to how offers are structured a change to the hot path's cost.

### Why not a cache

The other obvious shape is to cache the derived answer. That is the same
performance win with a worse failure mode: a cache is invalidated by code
remembering to invalidate it, and the first thing anybody forgets is the path
where a subscription lapses without anyone touching it.

### What the window buys

Stored rows carrying `valid_from` / `valid_until` avoid the cache's problem
entirely, because expiry is not an event anybody has to notice. The row stops
matching `now()` and the tenant stops being entitled — no job, no sweep, no
invalidation.

This is the same decision the offer's commercial window makes, for the same
reason, and it is worth stating as one rule: **in this platform a lapse is a
fact about the clock, never about whether something ran.** A subscription's
`status` column may sit at `ACTIVE` for a month after its period ended; the
tenant is entitled to nothing that whole month, and there is a test that
deliberately leaves the column stale to prove it.

Writes are cheap and rare — a subscription changes a handful of times a year —
so all the work sits where the work is, not on every request.

### Derived state, and what the audit trail actually is

Rewriting entitlements discards rows. That is safe because they are derived
state, not history: the history is `subscription_events`, which is append-only
and never rewritten, plus the offer versions those events point at, which
cannot be deleted while a subscription references them. Non-negotiable #18
asks for commercial history to be auditable; nothing an entitlement rewrite
destroys is part of it.

### Why source matters

A negotiated exception — support granting a customer more of something than
their plan includes — is a deliberate human act, and a plan change must not
silently undo it. `OVERRIDE` rows survive a rewrite; `SUBSCRIPTION` rows are
replaced.

Which forces a question when a feature is held twice, and refusing to answer
is not available: the caller needs one number. **Most generous wins** —
override first, then unlimited, then the largest limit. The alternative,
taking the smallest, would mean a support team's deliberate exception could
silently do nothing, which is the worse failure.

### Quotas are measured, not counted

A quota is checked against the current count of the thing itself —
`countForTenant` on the project repository — through a `UsageSource` the
project module implements.

The tempting alternative is a counter column incremented on create and
decremented on delete. It is faster and it drifts: the first time a delete
half-fails, or a row is removed by a cascade, the counter is wrong forever.
A quota enforced from a drifted counter refuses a customer who is within
their allowance and gives them no way to prove it.

If counting ever becomes too slow, the fix is a materialised count the
database maintains, not one the application remembers to update.

### Unmetered is a state worth naming

An offer can grant `max_storage` before storage exists. Three options: refuse
everything at zero, allow everything silently, or say the limit is not
enforced. The first locks customers out of what they paid for; the second
looks identical to a working limit. So `UsageMeter::measures()` returns false,
`assertMayConsume` allows, and `/tenants/current/usage` reports
`"metered": false` with `used` and `remaining` as null rather than a
confident zero.

### The fourth refusal

Three refusals existed. There are now four, and each is fixed in a different
place:

| Code | Means | Fixed by |
|---|---|---|
| `NO_TENANT_ACCESS` | not a member | an invitation |
| `PERMISSION_DENIED` | wrong role | a role change |
| `ENTITLEMENT_REQUIRED` | not bought | a subscription change |
| `QUOTA_EXCEEDED` | bought, and used up | an upgrade, or deleting something |

The last two are the ones most easily conflated, and conflating them sends a
customer to the wrong screen. `QUOTA_EXCEEDED` returns both the limit and the
usage, because a client told only that it is over cannot show a user how far.

### Absence grants nothing

An offer that does not mention `max_projects` grants **no** projects, not
unlimited ones. Every product must state what it sells, including
`{"limit": null}` for unlimited.

The alternative would make forgetting a line in a price list the way to give
something away for free — and the mistake would be invisible until a customer
found it.

## Consequences

- Every offer must grant `max_projects` explicitly, or its subscribers cannot
  create projects. That is the intended failure, and the first thing to check
  when a new offer's customers report being unable to do anything.
- Renewal exists as a service method with no endpoint. Renewing is what time
  does, and the job that notices it is M7; the behaviour is written and tested
  now rather than first exercised in production.
- Nothing marks a lapsed subscription `EXPIRED`. Because access is decided by
  the clock, that sweep is tidiness rather than correctness — it belongs with
  the same M7 scheduler.
- `max_users` is metered but nothing enforces it yet: no endpoint consumes a
  member seat through `QuotaPolicy`. The meter is wired so the usage endpoint
  is truthful; the enforcement point belongs with the invitation flow.
- Proration is absent. Changing offers moves entitlements immediately and
  leaves the period alone; the money side is M6.
