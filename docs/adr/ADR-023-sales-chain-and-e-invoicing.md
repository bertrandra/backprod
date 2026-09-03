# ADR-023 — The sales chain, and transmission as its own history

**Status:** accepted
**Decides:** §20's left half and §25.1's transmission records, completing M6
**Relates to:** Architecture V2 §20, §25.1, §12, non-negotiables #17 and #20

## Context

Parts 1 and 2 built the right-hand half of non-negotiable #20's chain:

```text
Quote → Order → Subscription/Purchase → Invoice → Payment
```

This part builds the left half and the piece §25.1 adds after it — proving an
invoice reached an approved platform.

## Decision

- **A quote lapses on the clock**, through a non-nullable `valid_until`.
- **A quote has no number.**
- **Accepting a quote and placing its order are one transaction**, and
  `orders.quote_id` is unique.
- **Fulfilment is one transaction**: subscription, invoice and completion, or
  none of them.
- **The invoice bills what the quote priced**, copied forward.
- **Transmission is its own table with its own four states**, one row per
  attempt.
- **Submission is resumable rather than transactional**, because it contains a
  network call.
- **The platform's verdict arrives by webhook**, applied exactly once by the
  same unique index as payments.

## Rationale

### The clock, for the fourth time

`valid_until` is `NOT NULL` and `Quote::isOpenAt()` asks the date, not the
status column. That is the same rule as an offer's commercial window, an
entitlement's validity and a subscription's period: **a lapse is a fact about
the clock, never about whether something ran.**

`EXPIRED` exists in the status table and nothing moves a quote into it. A test
deliberately leaves the column at `SENT` with the date a day past — the exact
state a platform with no sweeper is in — and asserts the quote cannot be
accepted. Marking it expired is tidiness for M7's scheduler, not correctness.

A quote with no expiry would be an open-ended price promise, so the column
refuses one.

### No quote numbers

French law requires invoices and credit notes to be numbered in unbroken
sequences. A devis is not subject to that. Giving quotes a number would mean a
second numbering scheme with different rules living beside the legal one, and
the predictable outcome is somebody eventually treating a quote number as
proof of something it is not.

### Why fulfilment is one transaction

`orders_completed_is_traceable` refuses a completed order that does not name
both a subscription and an invoice. That constraint is the point of the whole
chain — #20 wants it traceable end to end — and it can only hold if the three
writes cannot be separated.

Getting there needed the same move part 2 made for the payment webhook:
`SubscriptionRepository::applyActivate()` and `InvoiceRepository::applyIssue()`
now exist beside the transactional `activate()` and `issue()`, which delegate
to them. One writer, two entry points, no nested transactions anywhere — so
correctness does not depend on how DBAL handles nesting, which is a property of
the driver rather than of this design.

The cross-module coupling is a port, `OrderFulfilment`, so the sales repository
never learns what a subscription or an invoice is. That is the third use of
this seam (`PaymentSettlement`, `TransmissionEffect`, `OrderFulfilment`), and
at this point it is the codebase's standard answer to "these two modules must
be atomic".

### Why the quote's lines are copied, not re-derived

A quote exists to hold a price. If fulfilment re-priced from the offer, the
document would be decorative: a customer who accepted at 29.00 could be
invoiced at 99.00 because somebody repriced in between. So the lines travel
quote → order → invoice as data, and a test reprices the offer between quote
and fulfilment to prove the invoice does not move.

The invoice's own snapshot rule (ADR-021) is the same rule one step later; this
extends it back to the start of the chain.

What is *not* copied is the decision that the offer is still sellable.
Fulfilling an order whose offer has since been withdrawn is refused, because
starting a subscription on terms no longer on sale is a different mistake from
honouring a price.

### Transmission is not the invoice's status

An invoice has nine states; a transmission has four. They are separate tables
because an invoice can be rejected by a platform, corrected, and transmitted
again — and squeezing that into the invoice's status column loses the fact that
there were two attempts.

§25.1 requires the platform's identifiers and statuses to be kept, and the
reason to keep them is to be able to prove what happened. "It was rejected for
a missing SIREN, corrected, and accepted" is the answer an inspector wants;
"accepted" is not. So there is one row per attempt, and the endpoint lists all
of them.

`REJECTED → READY_FOR_EINVOICE` on the invoice is what makes the remedy
possible. `ISSUED → PAID` stays reachable too: not every invoice goes through
an approved platform — a bank transfer reconciled by hand does not — and
forcing one path would make the platform unusable for the invoices §25.1 does
not cover.

### Why submission is resumable rather than atomic

Submitting contains a network call, and holding a database transaction open
across one is how a connection pool dies. So the transmission row is opened
first, `PENDING`; the platform is called; the answer is recorded.

A crash in the middle leaves a `PENDING` row, and the next attempt resumes it
rather than opening a second — because two documents lodged for one invoice is
a real problem with the administration, not just untidiness. A transmission
already `SUBMITTED` refuses a second attempt outright.

This is a weaker guarantee than the payment webhook's and deliberately so: the
part that must be exactly-once is the *verdict*, and that is one transaction
with the same unique index.

### Two delivery logs, not one

`payment_events` and `einvoice_events` have the same shape and the same unique
index. Merging them into one polymorphic log would mean referencing either
subject by dropping the foreign key. A repeated shape is cheaper than a lost
referential guarantee, so they stay separate and the duplication is on purpose.

### Fail closed, again

With no configured signing secret there is no e-invoicing platform and nothing
can be transmitted — the same shape as the payment provider and the JWKS
source. It matters more here: a transmission record produced by an adapter
whose signatures nobody can verify would be worse than no record, because it
could be mistaken for evidence of compliance.

## Consequences

- Nothing sweeps lapsed quotes to `EXPIRED`. Access is decided by the clock, so
  that job is tidiness; it belongs with M7's scheduler alongside the
  subscription sweep.
- `AWAITING_PAYMENT` exists on an order and nothing sets it. Payment-gated
  fulfilment — order, pay, *then* subscribe — is a different flow from the one
  built here, and inventing it without a product decision would be guessing.
- A quote prices one offer, one line. Multi-line quotes are a real sales need
  and the schema supports them; no endpoint composes one yet.
- The stub platform is not a PDP. As with the payment stub, its name is
  recorded on every row it produces so it can never be mistaken for a real
  transmission.
- Nothing decides *which* operations require e-invoicing versus e-reporting.
  §25.1 is explicit that this depends on the operation, the customer's VAT
  status and their country, and that determination is a tax question this
  platform does not answer — the same boundary `VatPolicy` draws.
