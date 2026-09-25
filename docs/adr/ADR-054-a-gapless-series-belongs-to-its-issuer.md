# ADR-054 — A gapless series belongs to its issuer

**Status:** accepted, 2026-09-25.
**Amends:** the legal numbering rule in `CLAUDE.md` (Fiscalité / TVA) and
`docs/architecture-v2.md` § *Modèle de facture*; the schema of `invoices` and
`credit_notes` (`Version20260925100000`).
**Related:** [ADR-053](ADR-053-buying-covers-people-membership-does-not.md)
(buying covers people), and §26's rule that an invoice is kept, never edited.

## 1. Context

`DocumentNumbering` allocated one series per document type per year, for the
whole platform: the maximum number in `invoices`, plus one, read under a table
lock inside the caller's transaction. That was correct while the platform was
the only issuer — which it was, because the only invoice the platform raised
was *the product's supplier → the organisation*.

Since 2026-09-19 a seat is **the organisation selling to one of its own
people**: `InvoiceThenSubscribe` puts the organisation's legal identity in the
supplier block, the person in the customer block, and files the VAT in the
organisation's country. On 2026-09-25 the demonstration world was rebuilt on
that model, and three organisations began issuing invoices from one counter.

What that produced, read out of the database:

```text
2026-000001  Acme Ltd      →  ACME user1
2026-000002  Acme Ltd      →  ACME user1
2026-000003  Acme Ltd      →  ACME user4
2026-000004  Globex SA     →  Globex user1
2026-000005  Initech SARL  →  Initech user1
```

Initech raised exactly one invoice and it was numbered `2026-000005`. That
document tells its customer, and any auditor who reads it, that Initech issued
four documents before it. Initech cannot produce them, because they belong to
two other companies.

The other half is the one that matters more, and it is easy to miss while
looking at Initech: **Acme's own series has gaps in it.** Acme's invoices are
1, 2, 3 — and 4 and 5 are missing from Acme's books for ever, because they
went elsewhere. A gapless sequence is required precisely so that a missing
number is an answerable question, and this arrangement guaranteed unanswerable
ones for every issuer but the first.

## 2. Decision

**A number is continuous within the series of whoever issued the document.**

`invoices` and `credit_notes` carry `issuer_tenant_id`:

```text
issuer_tenant_id = <tenant>   the organisation issued it, under its own
                              legal identity, to one of its own people
issuer_tenant_id = NULL       the platform issued it
```

`DocumentNumbering::next()` takes the issuer and reads the maximum of **that
issuer's** series, with `IS NOT DISTINCT FROM` so that the platform's null is
one series rather than none.

Three consequences, each chosen rather than fallen into:

- **The issuer is not the tenant scope.** `invoices.tenant_id` is the
  isolation context and is the organisation either way — a seat Acme sold and a
  platform subscription Acme bought are both Acme's rows, and only one of them
  is Acme's document. Two columns, because they answer two questions.
- **The platform keeps one series across its products**, although its supplier
  identity is configured per product and two products *could* therefore be two
  legal entities. Splitting on that guess would have cut the platform's
  existing history into per-product pieces with gaps in each — the very defect
  being fixed, applied to the other issuer. If two distinct entities are ever
  configured, that is a second decision and it has to bring a plan for the
  history.
- **A credit note takes its number from the series of the invoice it
  corrects**, read off `Invoice::issuerTenantId` rather than decided again.
  Deciding again could put Acme's correction in the platform's series, and then
  Acme's books would hold an invoice with no correction against it.

**Uniqueness is enforced by the database**, not by the read under the lock:

```sql
CREATE UNIQUE INDEX invoices_number_unique_per_issuer
    ON invoices (issuer_tenant_id, number) NULLS NOT DISTINCT
 WHERE number IS NOT NULL;
```

`NULLS NOT DISTINCT` is what makes the index guard the platform's series at
all — a plain unique index treats every null issuer as a different one and
would have said nothing about two platform invoices numbered `2026-000001`.
Partial on `number IS NOT NULL`, because a draft has no number and any two
drafts would otherwise collide on `(NULL, NULL)`.

## 3. What this does not change

Nothing is renumbered. A legal number never changes, so every document raised
before today keeps the number it was issued with; they were all raised by the
platform, so `NULL` is already the right issuer for them and the migration is a
pure addition. The migration is reversible for that reason — although a
deployment where two organisations have both issued could not go back, because
their numbers would collide on the restored global constraint. It would fail
rather than merge two companies' series, which is the right failure.

The mechanism is unchanged: still `max + 1` under a `SHARE ROW EXCLUSIVE` lock
inside the caller's transaction, never a PostgreSQL sequence, because a
sequence burns a number when a transaction rolls back. The lock is still on
the whole table although the series is now one issuer's: it has to cover the
rows the read looks at, and narrowing it to a range of a partial index would
buy concurrency between organisations that issue a handful of documents a
month, in exchange for a lock this code has to reason about being right.

## 4. What it turned up

`StubPaymentProvider` minted its payment handle from the `$reference` — which
is the invoice's *number* — so the first invoice of one issuer and the first of
another produced one handle, and the second payment died on
`payments_provider_reference_unique` as a 500. `Payments` had passed the
invoice's row id as `$attemptKey` since 2026-09-18 with a comment saying why
("the invoice's row id never repeats, its number does"), and `StripePaymentProvider`
already keys on `$attemptKey ?? $reference`. Only the stub ignored it. Fixed
there, to the rule the port states.

It is worth recording that this was a latent defect with a real-world
counterpart — a reset demonstration world reissues `2026-000001`, and before
2026-09-18 that collided with the previous world's — rather than a
test-only artefact of this change.

## 5. Alternatives rejected

**Put the issuer in the number** (`ACME-2026-000001`). The number is what the
customer and the tax authority read; a tenant's invoice should look like a
normal company's invoice, not carry this platform's internal discriminator on
a legal document.

**Derive the issuer from `supplier_snapshot->>'legal_name'`.** A snapshot is
copied text and a legal name can change; the series would then split silently,
which is the failure mode this ADR exists to prevent, arriving without a
migration to notice it.

**Refuse to number a tenant-issued invoice at all** — i.e. keep the platform
as the only issuer and bill the organisation for its members' seats. That is a
commercial model the operator has decided against: the customer is the person,
the supplier is their organisation, and the money moves outside the platform.
