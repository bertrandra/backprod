# ADR-019 — Offers are versioned; plans are data, not code

**Status:** accepted
**Decides:** the commercial model for M5 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §12, §13, non-negotiable #18

## Context

§12 gives the chain the platform sells through — Offer → Subscription →
Entitlements → Tenant — with three definitions that the tables have to keep
apart: an offer is *what is sold*, a subscription is *what is subscribed to*,
and entitlements are *what the tenant may actually use*.

It also states two constraints outright: an expired offer is kept rather than
deleted, so commercial and financial history survives; and a material change
to price, quotas or features creates a new version rather than rewriting the
old one.

§13 adds the rule that governs how any of this may be consulted from code:
rights are entitlements, and `if ($plan === 'PRO')` is forbidden.

## Decision

- **`plans` is a table with a `rank`**, not an enum or a constant.
- **An offer's identity is separate from its terms.** `offers` holds what does
  not change; `offer_versions` holds price, billing period, commercial window
  and grants.
- **A subscription will point at an offer *version***, not at an offer.
- **`valid_from` / `valid_until` are the commercial window** — when a version
  may be sold — and are never the tenant's subscription period.
- **Sellability is decided against the clock**, in one place, not by status
  alone and not in SQL.
- **Money is stored as integer minor units** with an ISO 4217 currency.
- **A null grant limit means two things**, disambiguated by the feature's
  kind: nothing to count for a BOOLEAN feature, no ceiling for a QUOTA.
- **A CI gate rejects plan-name branching**, as §13's mechanical half.

## Rationale

### Why the plan is a row

A tier that exists as a constant invites exactly the code §13 bans, because
the name is right there to compare against. As a row with a rank, the only
question code can usefully ask is a comparison of two numbers — which is what
"is this an upgrade?" actually means — and the answer stays right when a tier
is renamed or a fifth one is added.

The rank also makes the *upgrade/downgrade* classification data-driven, which
matters in part 2 where a subscription change has to record which it was.

### Why the terms are versioned separately

If a subscription referred to an offer, then raising the price would change
what an existing customer had bought — silently, retroactively, and in a way
no invoice could later explain. Versioning is what makes "what did this tenant
agree to?" answerable a year later, which non-negotiable #18 requires of all
commercial data.

Splitting identity from terms also keeps the offer's code and name stable
across repricing, so a URL or a report that names an offer keeps meaning the
same thing.

### Why sellability asks the clock

A version's `status` is a fact somebody wrote down; its window is a fact about
time. Relying on status alone means an offer keeps selling after its end date
until a job runs to say otherwise — and the job not running is an outage that
looks like normal operation.

So the window is authoritative and `status` narrows it: `ACTIVE` *and* inside
the window. Storage filters coarsely by status, and `OfferVersion::isSellableAt`
answers the real question. Putting `now()` in the SQL as well would give one
question two implementations that agree until a boundary nobody tests — which
is why the boundary cases are unit-tested against the domain method rather
than inferred from an endpoint.

The window is half-open: open at `valid_from`, closed at `valid_until`. An
offer withdrawn "on 1 April" must not be sellable at midnight on the 1st, and
its replacement starting that instant must be — otherwise both, or neither,
are on sale for one tick.

### Why money is an integer

A price is a count of the smallest unit of its currency, and it is summed,
compared and invoiced. Floating point is wrong for all three. The column is
`BIGINT` named `price_minor_units` rather than `price_cents`, because not
every currency has cents.

Formatting is the client's job. A backend that returns `19.0` has already
destroyed the information a client would need to format it correctly.

### Why a null limit needs a flag beside it

`limit_value IS NULL` means "no ceiling" for a quota and "not applicable" for
a boolean capability. A sentinel like `-1` would avoid the ambiguity and
introduce a worse one: it compares as the smallest possible allowance, so a
single forgotten check turns unlimited into nothing. Null keeps the SQL honest
— `limit_value IS NULL OR used < limit_value` — and the feature's `kind` says
which of the two meanings applies. The API returns both the limit and an
explicit `unlimited` flag so a client never has to infer it.

### Why the catalogue hides what is not on sale

An offer with no sellable version is not yet launched or has been withdrawn.
Either way, what a company is about to sell or has stopped selling is
commercial information, and an id that answered differently for a draft than
for a fiction would be a way to enumerate it. So a withdrawn offer is reported
exactly as one that never existed — the same reasoning M3 applied to products.

The tenant who *bought* a withdrawn offer still needs to read its terms. That
is served by their subscription, which points at the exact version, and is the
one place a withdrawn offer legitimately remains visible.

### The gate

§13's ban cannot be enforced by Deptrac, which does not see string
comparisons. `tools/prove-no-plan-branching.php` catches the shapes it takes:
a comparison against a plan-ish variable, a comparison against a known tier
name, a tier name as a match arm or switch case, and a table in code keyed by
tier names.

That last group was added because the gate was tested before it was trusted,
and `match ($code) { 'ENTERPRISE' => … }` walked straight through the first
version — the same defect as `$plan === 'PRO'`, missed because the variable
was not called `$plan`.

## Consequences

- Nothing writes to the catalogue yet. Plans, features and offers are seeded
  by migration or by an administrator working directly against the database;
  the admin endpoints for authoring them are M8.
- Expiring a version is a status change plus a `valid_until`, done by hand or
  by a job that does not exist yet. Because sellability asks the clock, the
  absence of that job affects tidiness rather than correctness.
- Nothing marks one version "the current one". It is derived per request, so
  two versions left overlapping resolve to the newer rather than to whichever
  a flag happened to point at.
- A product with no plans and no offers simply sells nothing, which is the
  correct state for a product that has not been priced yet.
