# ADR-022 — The webhook is the source of truth, and it applies exactly once

**Status:** accepted
**Decides:** how §24's payment path works, for M6 part 2 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §24, §25.1, §31, non-negotiables #17 and #20

## Context

§24 puts the payment service provider outside this platform and says plainly
that the server webhook is the source of truth. Part 1 built invoices; this
decides how one gets paid.

The hard part is not taking money. It is that a webhook is delivered by
someone else, over a network, more than once. Providers retry until they get a
2xx; load balancers duplicate; a retry of an old event can land after a newer
one. Every one of those is normal traffic, and each has a way of corrupting
money if handled naively.

## Decision

- **The webhook is the only thing that marks a payment collected.** Nothing in
  the API can.
- **Exactly-once is a unique index, not a check.** The delivery is recorded
  against `UNIQUE (provider, provider_event_id)` inside the same transaction
  that acts on it.
- **The signature is verified over the raw bytes, before parsing.**
- **Payment status is a one-way machine.** A move that is not permitted is
  recorded and ignored, never applied.
- **The invoice is settled inside the payment's transaction**, through a
  participating repository method rather than a nested transaction.
- **Providers are a registry**, not a dependency.
- **A chargeback does not rewrite the invoice.**
- **Credit notes get their own gapless series**, in the same mechanism as
  invoices.

## Rationale

### Why the index and not a check

The obvious implementation is to look for the event id first and skip if it is
there. It is wrong, and it is wrong in the way that is hardest to see in
testing: two copies of the same delivery arriving at once both find nothing,
both proceed, and money is counted twice. Load makes it more likely, so it
appears in production and not before.

A unique index cannot be raced. Recording the delivery *first* inside the
transaction means the second copy hits the constraint before it can do
anything else — and because PostgreSQL aborts the whole transaction on a
constraint violation, every statement after it in that transaction is refused
too. The exactly-once property is therefore the database's, not the
application's.

The exit criterion is asserted on the **ledger**, not only on statuses. Setting
a status twice is harmless; appending `PAYMENT_SUCCEEDED` to a financial ledger
twice is revenue counted twice, which is the failure webhook replay actually
causes.

A replay is answered **202**, never an error. A provider retries anything that
is not a 2xx, so telling it "already handled" with a 500 is how a retry storm
starts.

### Why verification comes before parsing

A signature is over bytes. Parsing first and verifying the result verifies
something the provider never signed — and the gap between the two is where a
tampered field lives. So `verify(string $rawBody, array $headers)` takes the
body exactly as received, and nothing in it is trusted until that returns,
including which payment it claims to be about.

That ordering is also what makes it safe to resolve a tenant from the body
without violating ADR-015's "never trust a client-provided tenant id". The
handle was minted by the provider and the bytes are proven to be theirs; it is
not a claim from a client.

This is the only unauthenticated write endpoint in the platform, so
`RoutePolicy` gained a `publicPrefixes` list to carry it. Public prefixes are
the most dangerous kind of route — everything under one is reachable by
anybody — and the rule stated there is that a handler behind one must
authenticate the request itself.

### Why the status machine is one-way

Providers deliver out of order. A retry of `payment.failed` can arrive after
the `payment.succeeded` that superseded it, and applying whatever turns up
last would reverse a collected payment on the strength of a stale message.

So `SUCCEEDED` leads only to the ways money leaves again, and `FAILED` leads
nowhere: a retry by the customer is a *new* payment with its own provider
reference, because they may have used a different instrument and the two must
be tellable apart.

"Not permitted" is recorded as `IGNORED_STALE` rather than discarded. An
operator looking at a payment that did not activate needs to know whether the
delivery never arrived, arrived for something unknown, or arrived too late —
three different problems that look identical if the answer is silence.

### Why the invoice moves in the same transaction

A collected payment and an invoice still saying it is owed must never be
observable together: that state is a customer being chased for money they have
paid, and a crash between two transactions makes it permanent.

The obvious way to get atomicity is to nest transactions. That makes
correctness depend on how the driver handles nesting, which is a property of
DBAL rather than of this design and is not something this repository tests. So
instead `InvoiceRepository` exposes `applyTransition()` — the same write,
without a transaction of its own — and the transactional `transition()`
delegates to it. One writer, two entry points, no nesting anywhere.

The coupling between the modules is a port, `PaymentSettlement`, so the
payment repository never learns what an invoice is.

### Why no card data, structurally

§24 is explicit, and a comment saying so is not enforcement. There is no
column in the schema that could hold a PAN, an expiry or a CVV, and
`payments.method` is constrained to a small set of labels — so an adapter that
tried to put an instrument there is refused by the database. A test asserts
that refusal by attempting it with a card number.

The one credential that exists — the provider's client secret for completing
the payment — is returned to the caller and never stored (§31).

### What a chargeback does, and deliberately does not

A chargeback is money taken back by the customer's bank. It sets the payment
to `CHARGEBACK` and appends to the ledger. It does **not** move the invoice.

An invoice's status is a legal statement about a document, and a bank dispute
is not a decision this platform gets to make on the customer's behalf. The
instrument for correcting a paid invoice is a credit note, which somebody
issues deliberately. Silently un-paying an invoice would also mean an invoice
could leave `PAID`, which the state machine forbids for good reason.

The consequence is real and named below: nothing yet suspends access for a
tenant who charged back.

### Refunds and chargebacks are one table

The movement of money is identical; only who decided differs. A report asking
"what did we pay back last quarter" should not have to union two tables to get
the total right, so a chargeback is a `reason` on a refund. `Refund::isDisputed()`
and the presenter's `disputed` flag are what keep the distinction visible where
it matters.

### Credit notes, and the numbering that was already there

A credit note is a legal document with its own gapless sequence — sharing the
invoice series would leave both with gaps. Rather than write the numbering
twice, part 1's `max + 1` under a table lock moved into `DocumentNumbering`,
which now serves both series. The table name is interpolated into SQL because
an identifier cannot be a bound parameter, so it comes from a fixed map that
no caller can influence.

Crediting is **full only**. A partial credit needs the caller to choose lines
or an amount and needs VAT apportioned across rates; guessing at either would
produce a legal document nobody asked for. Crediting in full and reissuing is
the correct handling of a wrong invoice anyway.

## Consequences

- Nothing suspends a tenant whose payment was charged back. The payment says
  `CHARGEBACK` and the ledger records it; acting on that is a policy decision
  and belongs with M8's admin surface.
- A payment against a cancelled invoice records as succeeded and leaves the
  invoice alone. The money genuinely arrived and pretending otherwise would
  lose it; what remains is a visible anomaly rather than a hidden one.
- The stub provider is not a payment provider. It exists so the whole path is
  exercised without a real PSP and so the first real adapter has a worked
  example; its name appears in `payments.provider`, so a row it produced can
  never be mistaken for a real one. A deployment with no configured signing
  secret has *no* provider and cannot take money — the same fail-closed shape
  as the JWKS source.
- Partial credit notes do not exist yet.
- `orders` and `quotes` are not here. §20's chain is
  Quote → Order → Subscription → Invoice → Payment, and this part built the
  right-hand half; the sales half is part 3.
