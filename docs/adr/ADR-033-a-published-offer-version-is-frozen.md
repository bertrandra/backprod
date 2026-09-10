# ADR-033 — A published offer version is frozen, and the database is what freezes it

**Status:** accepted
**Decides:** how the catalogue may be written to
**Relates to:** Architecture V2 §12, §10.2, §13.1; uses
[ADR-020](ADR-020-entitlement-resolution.md)

## Context

§12 has said since M2 that "une modification importante du prix, des quotas
ou des fonctionnalités crée une nouvelle version plutôt que de réécrire
l'historique", and the schema was built for it: a subscription points at an
`offer_versions` row, not at an offer, precisely so that what a tenant bought
stays legible after the offer moves on.

Nothing enforced it. There was no way to write the catalogue at all — every
offer was inserted by hand in SQL — so the rule was a convention held by
whoever was typing. The moment there is a `PATCH`, a convention is not enough:
an `UPDATE` on a sold version's price rewrites the terms of a contract that is
already running, and every invoice raised against it becomes unexplainable.

## Decision

**A version that has left `DRAFT` is frozen by a trigger.** Price, currency,
billing period, start date and all six commitment terms refuse to change.
`offer_version_features` freezes on the same condition, because a quota edited
after the sale is exactly the case §12 names — guarding the price and leaving
the grant editable would guard the smaller half.

Two fields stay mutable on purpose. `status`, so a version can expire or be
archived; and `valid_until`, so an offer can be withdrawn from sale. Neither
changes what anybody bought.

**A published version cannot be deleted either.** The foreign keys already
RESTRICT where a subscription exists; the trigger covers the version nobody
has bought yet but which has been published, and which some later reader will
expect to find.

**Two versions of one offer may not be on sale at once**, as a partial
exclusion constraint over `tstzrange(valid_from, valid_until)` where the status
is ACTIVE. `OfferCandidate::sellableAt` already resolved an overlap by taking
the newest, which is the right way to *read* data that already exists and the
wrong way to let new data be written: "what does this offer cost today" would
have two defensible answers, and which one a customer saw would depend on
which code path asked. The constraint makes the tie-break unreachable rather
than load-bearing.

**Version numbers are `max + 1` inside the INSERT**, never read and written
back. Two authors adding a version at the same moment would otherwise both
read 3, both write 4, and one would get a 500 from the unique index instead of
version 5.

**Drafts sit behind `catalog.manage`, not `catalog.read`.** What a company is
about to launch, and at what price, is commercial information. `GET /offers`
and `GET /offers/{id}` show a draft to nobody; the authoring listing shows all
of them, to the role that writes them.

## Consequences

The endpoints are thin because the rules are not in them. `OfferAuthoring` is
five delegating methods: every invariant is a constraint or a trigger, and
re-checking any of them in PHP would create a second opinion that can disagree
with the first. What is left in the service is sequencing.

There is deliberately no way to edit a published version. A price change means
a new version, which is more calls than a `PATCH` would have been, and is the
rule §12 asked for rather than a workaround for not having implemented it.

The exclusion constraint applies to rows that already exist. A deployment
whose `offer_versions` already contains two overlapping ACTIVE versions of one
offer will fail this migration — which is the correct outcome, since that data
already makes the catalogue ambiguous, but it is a migration that can fail on
real data rather than one that cannot.

Every case was verified against a live PostgreSQL 16 before the endpoints were
written: five refusals, four permitted paths, and each of the three
cross-product refusals in the adapter's own statements. That replay found a
bug in the grants trigger — deleting a `DRAFT` version cascades to its grants,
by which point the parent row is gone and the status lookup returns NULL, so
the trigger refused a deletion it was never meant to guard. The cascade is now
its one exception. `TRUNCATE` does not fire row triggers, so no test would
have caught it.
