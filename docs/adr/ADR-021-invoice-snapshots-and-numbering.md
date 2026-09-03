# ADR-021 — An invoice is a snapshot with a gapless number

**Status:** accepted
**Decides:** how §25's invoicing works, for M6 part 1 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §25, §25.1, §12, §13, non-negotiable #20

## Context

§25 requires an invoice to store its own snapshot and never derive from
current plan values. M5 built the thing an invoice would otherwise derive
from: offers, versions and subscriptions, all of which change. This decides
what "snapshot" means concretely, and how a document gets its number.

Two constraints make this different from the rest of the platform. An invoice
is a **legal artefact**: in France it must be numbered in an unbroken
sequence, carry mandatory mentions, and be retained for ten years. And it is
**immutable in practice**: a wrong invoice is not edited, it is cancelled or
credited and reissued, with both documents kept.

## Decision

- **Everything variable is copied onto the document at issue time**: both
  parties, each line's description, unit price, discount and VAT rate, and
  the tax totalled per rate.
- **The offer version is recorded for lineage only.** No amount is ever read
  back through it.
- **The number is allocated from `max + 1` under a table lock**, inside the
  same transaction that writes the document — not from a PostgreSQL sequence.
- **Money is an integer count of minor units plus a currency**, everywhere,
  including in JSON.
- **VAT rates are basis points**, and come from per-product configuration.
- **The supplier identity is per-product configuration too**, and a product
  without one cannot invoice.
- **Transitions go through a state machine** that starts narrower than the
  schema.

## Rationale

### Why a snapshot rather than a view

The efficient shape is a join: an invoice row pointing at a subscription
pointing at an offer version, rendered on demand. It is smaller, it cannot
drift, and it is wrong.

An invoice is a statement about a moment. Repricing an offer, renaming a
company or correcting an address must change nothing about a document already
filed by somebody's accountant. Under the join shape, every one of those
edits silently rewrites history — including history a tax authority has a
copy of.

So the snapshot is not a denormalisation for speed. It is the correctness
requirement, and the storage cost is the point rather than a compromise.

The exit criterion is written to prove exactly this: a test issues an invoice,
then re-versions the offer *and* reprices the old version underneath it —
something production would never do, included because it is the strongest
available form of the assertion — and reads the invoice back unchanged. An
invoice that read its amounts through a reference would move; this one cannot,
because there is no reference to read through.

### Why not a sequence for the number

`CREATE SEQUENCE` is the obvious answer and the wrong one. Sequences are fast
precisely because they do not participate in transactions: a rolled-back
insert burns its number permanently. That is a feature for a surrogate key
and a defect for a legal one — French numbering must be sequential and without
gaps, and a missing number is not cosmetic. It is a question from an auditor
about an invoice nobody can produce, and "our database skipped it" is not an
answer anyone has to accept.

So the number is `max + 1` computed inside the transaction, with
`LOCK TABLE invoices IN SHARE ROW EXCLUSIVE MODE` held while it is read and
written. Two concurrent issues cannot read the same maximum; the unique
constraint on `number` is the backstop rather than the mechanism.

The cost is that issuing serialises. That is the right trade: invoicing is
rare — a handful of documents per tenant per year — and its correctness is
legal rather than merely important. A test rolls back a mid-flight issue and
asserts the next number is unchanged, because that is the exact behaviour a
sequence would get wrong.

Numbers are scoped per year (`2026-000001`), which is conventional and keeps
the sequence legible. The year boundary is the one place the maximum resets.

### Why integers all the way out

Money is minor units and a currency, in the domain, in the database and in
JSON. A client that receives `{"minor_units": 1999, "currency": "EUR"}`
cannot round it wrong on the way in; one that receives `19.99` already has,
in whatever binary float its parser produced.

VAT is basis points for the same reason one level down: France charges 5.5%
on several things, which is `550` exactly and `0.055` approximately. Rounding
is half-up on the magnitude, in one place — `Money::taxedAt` — rather than
wherever each call site reached for. Half-up on the *magnitude* rather than
towards positive infinity, so a credit line exactly undoes the invoice line
it corrects.

### VAT policy is not a tax engine, and says so

§25.1 is explicit that correct VAT treatment depends on the nature of the
operation, the customer's VAT status and their country: reverse charge on
intra-EU B2B, exemptions, distance-selling thresholds. None of that is
decided here.

`VatPolicy` applies a configured rate per country and zero otherwise. What
makes that honest rather than negligent is that the rate used is stored on
the line and in `tax_records`, so the gap is visible on the document instead
of buried in code that looked like it knew. Inferring a treatment from a
country code would produce confidently wrong invoices, which is strictly
worse than visibly incomplete ones.

### The supplier is configuration, and its absence is a refusal

A French invoice must name its issuer. That identity belongs to the legal
entity behind a product, so it is `product_configuration` under
`billing_supplier` — a second product sold by a second company gets its own
without any code learning either product's name (§12.1).

A product without one is refused, before a number is allocated. That
ordering is the whole point: numbering is gapless, so a document raised by
mistake cannot be deleted, only cancelled and explained. Failing early costs
a 409; failing late costs a permanent row in a legal sequence.

The same reasoning applies to the customer: no billing profile, no invoice.

### Nine states in the schema, five reachable in code

`invoices.status` accepts all nine states of §25.1 from the start, so the
e-invoicing adapter needs no migration to widen a constraint. Which
transitions are *reachable* is decided in `InvoiceStatus`, and this milestone
declares only what it can actually perform: submission arrives with the
e-invoicing adapter, and the automatic payment path with the payment adapter.

Absent is a refusal, not a permission — an unknown status grants nothing. So
wiring either adapter has to extend the table deliberately rather than
discovering it already worked.

The refusals are the interesting half. Re-issuing an issued invoice would
allocate a second legal number for one document. Paying a cancelled one would
put money against a debt that no longer exists. Cancelling a paid one would
erase a payment that happened — the instrument for that is a credit note.

### Cancelling is voiding, never deleting

A cancelled invoice keeps its number and its row. An auditor asking about
`2026-000042` must get an answer, and "cancelled" is an answer; "no such
invoice" is a gap. The next issue continues the sequence rather than reusing
the number.

For the same reason `invoices.tenant_id` and `invoices.product_id` are
`ON DELETE RESTRICT`. §25.1 separates legal accounting retention from RGPD
erasure, and this is where that separation becomes structural rather than a
convention: a tenant with invoices cannot be deleted. Honouring an erasure
request against invoiced data is a redaction problem for M8, not a cascade.

### The ledger

Every issue and every transition appends to `financial_events`, which is
append-only. It exists now, with two event types, because a financial audit
trail retrofitted after payments and refunds exist is an audit trail with a
hole in exactly the period anyone would want to inspect.

## Consequences

- Issuing serialises on a table lock. At this platform's volumes that is
  free; at a volume where it is not, the fix is per-year or per-entity
  partitioning of the lock, never a sequence.
- Nothing decides *when* to bill. `POST /api/v1/billing/invoices` bills the
  current subscription period on demand, and M7's scheduler is what will call
  it. Until then a tenant can be invoiced twice for one period by asking
  twice; a period-uniqueness guard belongs with the scheduler that makes it
  meaningful.
- Proration is still absent. Changing offers moves entitlements immediately
  and leaves the money alone.
- Nothing yet reaches `READY_FOR_EINVOICE` or `SUBMITTED`, and no credit note
  exists — `CREDITED` is a legal target with nothing to issue the note. Both
  arrive in the later parts of M6.
- `due_at` is stored but never set: payment terms are a single constant
  mention for now, and computing a due date from them is part of the payment
  work rather than a guess made here.
