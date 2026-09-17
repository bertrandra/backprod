# ADR-034 — A checkout session is an order

**Status:** accepted
**Amended on 2026-09-17** — a checkout is refused `409 SUBSCRIPTION_ALREADY_ACTIVE`,
before the order and its gaplessly numbered invoice, while the tenant's own
subscription to the product is live; so are `placeOrder` and `acceptQuote`.
The schema's one-active-subscription index says the same thing, but an index
refuses last — after the invoice and the card — and the operator's deployment
showed what that costs: a second offer bought beside a live subscription,
charged twice, and a webhook that could only ever fail. What the refusal
cannot reach is the race, two sessions opened before either was paid; there
the money is recorded, the order is **held** rather than the delivery failed,
the session reads `HELD`, and an `ORDER_HELD` ledger row names why. See
`Sales::refuseWhileSubscribed` and `CompleteOrderOnPayment`.
**Decides:** how `/checkout/sessions` is modelled
**Relates to:** Architecture V2 §7, §24; uses
[ADR-024](ADR-024-payment-gated-activation.md)

## Context

§7 lists `POST /checkout/sessions`, `GET /checkout/sessions/{id}` and
`POST /payments/{id}/retry`. Everything they need already existed by the end
of M6: `Sales::order` places an order against an offer, `Sales::fulfil` raises
its invoice, `Payments::start` asks the provider to authorize, and the
webhook activates the subscription when the money lands.

So the question was not how to take a payment. It was what a *session* is,
given that a lifecycle with exactly this shape is already recorded.

## Decision

**There is no `checkout_sessions` table.** The id a session returns is the
order's id, and everything a session row would have held — is it paid, what
was invoiced, did the subscription start — already lives on the order, the
invoice and the payment.

A second row tracking the same lifecycle is a second answer that can disagree
with the first, and the first is the one the money is attached to. Every way
that disagreement could arise is a real one: a webhook that arrives while the
session row is being written, an operator reconciling a transfer against the
invoice directly, a refund. Each would leave a session saying one thing and an
invoice saying another, and the invoice would be right.

**`status` is derived on read**, from the order's status and the latest
payment's, for the same reason. The one state it adds is `PAYMENT_FAILED` —
the order still awaits payment and the last attempt is dead — because
reporting that as `AWAITING_PAYMENT` would hide that nothing is coming, which
is exactly when a caller needs to retry.

**The service composes; it does not re-implement.** Every rule in the chain
still applies and none is copied: the offer must be on sale, the tenant must
have a billing profile, the invoice is numbered gaplessly, and **the
subscription still waits for the money**. A checkout that activated on
creation would extend credit to everyone who can reach the endpoint, which is
precisely what ADR-024 decided against.

**A retry is a new payment.** `PaymentStatus` is one-way and already said why:
the customer may have used a different instrument, and two attempts that must
be told apart cannot share a provider reference. Retrying is refused while the
previous attempt is in flight — a second authorization then risks collecting
twice for one debt — and refused when it succeeded, where there is nothing to
retry.

**The reference names the attempt, not the invoice.** `Payments::start` used
to hand the provider the invoice number, which made that last sentence a
claim the code did not honour: the second attempt asked the provider to
authorize the same thing again, so an idempotent provider would return the
intent that had already failed and the unique index on `(provider,
provider_payment_id)` would refuse the row. The reference is now
`<invoice number>/<attempt>`, still readable by whoever reconciles the two
systems side by side. The attempt number is counted from the existing
payments, and it is a label rather than a lock: two callers racing here read
the same count, and what keeps one attempt from existing twice is the unique
index, not the number.

## Consequences

The three calls are not one transaction and cannot be, because the middle step
talks to a payment provider over the network. What makes that acceptable is
that each step leaves a durable, addressable record: a caller whose connection
drops after the invoice is raised has an order they can look up and retry the
payment on, rather than a charge nobody can account for.

`client_secret` is returned when a session is opened and never again. It is
short-lived and it is a credential, so §31 keeps it out of the database —
which means `GET /checkout/sessions/{id}` has nothing to return it from. A
caller who needs a fresh one retries the payment, which is a new attempt and
gets its own.

If a future session ever needs state the order genuinely does not have — an
expiry independent of the order, a provider-hosted page and its URL — it gets
a table then. The response already carries `order_id` beside `id` so that
naming stays honest about what today's session is.

A session's `status` has one value the order's own status does not: `HELD`,
derived when the payment is settled and the order is not completed. It is the
mirror of `PAYMENT_FAILED` — that one says nothing is coming although the
order is still waiting; this one says the money came and nothing could be
started against it. Both exist because the truthful sentence is neither
"awaiting" nor "completed", and a customer reading their own checkout is owed
the truthful one. The catalogue hides Buy while the subscription is live, as
courtesy; the 409 is the rule.
