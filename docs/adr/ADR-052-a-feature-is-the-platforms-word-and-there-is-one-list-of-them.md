# ADR-052 — A feature is the platform's word, and there is one list of them

**Status:** accepted, 2026-09-24.

## Context

`features` carried a `product_id`, and `features_code_unique` was on
`(product_id, code)`. Every product therefore had a catalogue of its own,
and a code meant whatever that product's row said it meant.

The demonstration world seeds five products. `max_projects` existed five
times. So did `users`, `exports` and `white_label`.

That would be merely wasteful if nothing read those codes by name. Things
do:

```text
ProjectWorkspace::QUOTA            'max_projects'
SubscriptionPeople::USERS_FEATURE  'users'
```

Both resolve an entitlement by **code**, per (tenant, product). The quota a
customer is held to therefore depended on every product's catalogue having
been seeded with the same spelling *and the same kind* — a seeder typo, or
one product's `max_projects` created as a `BOOLEAN`, and the workspace
would either enforce nothing or refuse everything, silently, for that
product only.

There was no screen for any of this either. A feature was created through
`POST /api/v1/staff/catalogue/features?product=CODE`, under
`staff.catalog.manage` — the permission ADR-040 lets the platform *lend to
a tenant*. A reseller maintaining their own price list could invent a
priced capability whose code another product's program gates on.

And the operator's own request (`docs/translatable-fields-spec.md` §4, on
their instruction) was plainer than any of this: a feature has a
programmatic effect in the target, so the list of them should be
predefined, kept in one place, and picked from.

## Decision

**A feature is a word the platform and a product's code have agreed on, and
there is one list of them.** `features.product_id` is gone;
`features_code_unique` is on the code alone.

**A grant is still a product's.** It lives on an offer version and an offer
belongs to a product, so what a tenant holds is still decided per (tenant,
product), and a feature nobody granted them is still absent. Nothing about
entitlement resolution moved. What moved is the vocabulary those grants are
written in.

**Keeping the list is its own permission**, `staff.features.manage`, held by
PLATFORM_ADMIN and by nobody else. Deliberately not `staff.catalog.manage`:
pricing a product and deciding what a capability *is* are different trusts,
and the first one is lendable.

**Retiring replaces deleting.** A feature some offer version grants can
never be removed — the entitlements resting on it are what customers are
paying for — so the list gains `active`. A retired feature keeps entitling
everybody who already holds it, keeps its code taken, and stays on the
list; what stops is a new offer version granting it, refused where that
version is written rather than checked in a service.

**The screen is Console → Features**, before Catalogue in the navigation,
because a catalogue picks from the list. No product appears on it — not in
a route, not in a query parameter that would read as scoping and scope
nothing.

## The migration

`Version20260924110000`, and it is the part that can go wrong quietly, so
it refuses rather than guesses:

- two rows sharing a code but disagreeing about `kind` or `unit` **stop the
  migration** with the codes named. Merging a `QUOTA` into a `BOOLEAN`
  would reinterpret every grant written against one of them, which is a
  price somebody is paying — ADR-033's rule applied to the thing being
  priced;
- the survivor of each code is the oldest row, by `created_at` then `id`,
  so a rerun against a restored dump picks the same one;
- `feature_translations`, `offer_version_features` and `entitlements` are
  repointed inside the same transaction as the delete, so there is no
  moment at which a live subscription grants a feature that does not exist.

Irreversible (ADR-016): `down()` would have to invent which product each
merged feature belonged to, and the rows that knew are exactly what the
migration removed.

## Consequences

- `max_projects` is one row. The code that reads it no longer depends on
  five seeders having agreed.
- A tenant lent `catalog.manage` can price offers and cannot invent a
  capability.
- An offer may grant any feature on the list, including one first created
  for another product's sake. That is the point, and it is also the thing
  to watch: the list is now a shared vocabulary, and a code added carelessly
  is added for everybody. `active` is what takes one back out of
  circulation.
- A product's own codes — `plan.terrasse` and its siblings — live in the
  same list. The product remains the authority on what they *mean*; the
  platform carries the word. When a product declares them itself
  (`PUT /api/v1/product/capabilities`, ADR-051 §4), what arrives is checked
  against this list rather than written into it: a program may say what it
  gates on, and may not invent a priced capability on its own.
- `FEATURE_CODE_TAKEN` now means taken platform-wide, retired rows
  included. The console lists the retired for that reason: a code refused
  with nothing on screen to explain it is worse than a code refused.
