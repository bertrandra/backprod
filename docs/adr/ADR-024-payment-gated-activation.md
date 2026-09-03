# ADR-024 — A subscription starts when its invoice is paid

**Status:** accepted
**Decides:** when an order's subscription begins, and what fires it
**Relates to:** Architecture V2 §20, §24, §25, non-negotiable #20; supersedes
part of [ADR-023](ADR-023-sales-chain-and-e-invoicing.md)

## Context

ADR-023 made fulfilling an order one transaction: the subscription started,
the invoice was raised, and the order completed together. That shape was right
about atomicity and wrong about timing. It activated on the assumption that
the money would follow — which is a decision to extend credit to everyone who
can reach `POST /sales/orders/{id}/fulfil`, taken by default rather than on
purpose.

The evidence that it was never the intended end state was already in the
schema. `orders.status` has allowed `AWAITING_PAYMENT` since the table was
created and nothing ever set it; `payments.order_id` was added and never
written. Both were placed for a gate that had not been built yet.

Two things had to be decided: what releases the subscription, and how the
release stays atomic with whatever proved the money arrived.

## Decision

- **Fulfilling an order raises its invoice and parks the order at
  `AWAITING_PAYMENT`.** The subscription is not started, no entitlement
  exists, and the response says so — `subscription_id` is null until it is
  earned.

- **The trigger is the invoice reaching `PAID`, not a card succeeding.** §25
  makes two routes to `PAID` legitimate: a provider's webhook, and an operator
  reconciling a bank transfer by hand. A gate hung off only the first would
  leave every transfer-paying customer paid up and switched off. Both routes
  fire one port, `InvoicePaid`, so the rule is one sentence: *what was bought
  starts when the invoice for it is paid, however it was paid.*

- **The release participates in the transaction that recorded the payment.**
  `applyCompleteOrder()` joins `applyActivate()`, `applyIssue()` and
  `applyTransition()` as a participating method beside a transactional twin.
  Money collected and a subscription still off must not be observable, not
  even after a crash between the two — and nothing in this repository nests
  `transactional()` calls.

- **Invoicing asks what is on sale; activating asks what was sold.** The two
  halves resolve the offer through different lookups on purpose.
  `offerOnSale()` refuses to invoice against withdrawn or repriced terms,
  which costs nobody anything because no money has moved yet.
  `offerAsSold()` ignores the clock and the sale status entirely, because by
  then the customer has paid and refusing would take their money and give them
  nothing.

- **An order with nothing to collect completes at fulfilment.** A free offer
  prices an order at zero; the invoice is still raised, because a €0 document
  is still the record of what was sold, but there is no payment to wait for
  and parking it would strand the order forever.

- **An invoiced order is undone by crediting its invoice**, from the moment
  the invoice exists rather than only once the order completes.

- **Two new invariants are the database's, not the code's.**
  `orders_awaiting_payment_has_invoice` refuses an order waiting for money it
  has raised no invoice for. A partial unique index on `orders.invoice_id`
  makes one invoice belong to exactly one sale — without it, two orders
  sharing an invoice would let one payment start two subscriptions, and the
  settlement lookup is *by* that invoice.

## Consequences

- The window between fulfilment and payment is a real state a client must
  handle: an order that is neither pending nor complete, with an invoice to
  pay. That is the gate being visible rather than a wrinkle.

- An order abandoned at `AWAITING_PAYMENT` stays there. It cannot be
  cancelled, because its invoice is issued and gapless numbering means an
  issued document is undone by a credit note, not deleted. Sweeping those is
  M7's, alongside lapsed quotes and subscriptions — and, as everywhere else in
  this platform, the lapse is a fact about the clock rather than about whether
  the sweep has run.

- An order's invoice is raised before its subscription exists, so it cannot
  name it at issue time. `applyAttachSubscription()` writes that link when the
  subscription starts. It is a reference, not a priced fact: §25's snapshot
  rule governs what the document says was sold and for how much, and none of
  that moves.

- `payments.order_id` is written when the order completes, and covers every
  attempt against that invoice including the ones that failed. §20 wants the
  chain followable in both directions, and a reconciliation starts from the
  payment.

- Replaying a webhook still activates exactly once, and now for three
  independent reasons: `payment_events_delivered_once` refuses the delivery
  before it reaches this code; the settlement lookup asks for an order *still
  awaiting payment*, which a completed one is not; and
  `subscriptions_one_active_per_product` is the backstop if both ever failed.
  Two payments succeeding against one invoice — a customer who retried and was
  charged twice — serialise on the invoice row: the second transaction blocks
  on the same `UPDATE invoices`, and by the time it looks for an order to
  release, the first has committed one that is no longer awaiting payment.

- ADR-023's "fulfilment is one transaction — subscription, invoice and
  completion" no longer holds as written. It is now two transactions, each
  atomic: invoice-and-park, then activate-and-complete.
