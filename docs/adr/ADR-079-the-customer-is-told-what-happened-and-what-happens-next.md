# ADR-079 — The customer is told what happened, and what happens next

**Status:** accepted (2026-11-27)
**Decided by the operator on 2026-11-27:** cancelling before a period invoiced
ahead begins *cancels the invoice, credits the card with the amount in question
and raises the documents that needs.*
**Amends:** ADR-070 (the customer is asked before the period), ADR-071 (the
pre-renewal notice), §20 (a held order)
**Relates to:** ADR-058 (a refund carries a credit note), ADR-060 (arrears),
ADR-067 (a handler is not work until something enqueues it), ADR-078

## Context

The audit of the whole subscription cycle found that the platform spoke to its
customers about money in exactly one direction: when it wanted some. Everything
else a person needed to know, they had to infer.

- **A purchase was silent.** Nothing said the seat had started, nothing said the
  card was refused, nothing said the money taken for a second seat had gone back.
- **A held order was a dead end.** Two checkouts opened before either was paid:
  the second payment arrives for a seat already live, the order is rightly held
  rather than failed — and then nothing read `ORDER_HELD`. The screen promised a
  refund. Nobody made one.
- **Somebody in arrears could buy again.** The refusal at placement looked for an
  `ACTIVE` seat; the index counts `PAST_DUE` too. The sale went through, the money
  was taken, the order was held, and the old seat stayed shut.
- **An abandoned checkout was abandoned for ever.** A closed tab left an order
  awaiting payment with an issued invoice whose VAT sat in the organisation's
  return. And a provider outage inside `Checkout::open` returned an error with no
  session, so a second click raised a second invoice.
- **Cancelling in the renewal lead cost a period more.** The renewal rolls the
  row and raises the next invoice a lead early (ADR-070). "At period end" then
  meant the end of a period that had not begun.
- **A late notice promised a renewal that would not happen.** The pre-renewal
  notice was raised even when it could no longer be honoured; the term then ended
  at its date with nothing further said.
- **The screen showed a period and a status**, and left the rest to be worked
  out: when the term ends, whether it renews, the last day to refuse.

## Decision

### A purchase is told to the person who made it

Three notices, `purchase.confirmed`, `purchase.payment_failed` and
`purchase.refunded` (`PurchaseNotices`), each raised **on the transaction of the
fact** and sent by the queue — never inside the request or the webhook. Addressed
to the person the order is for, and nobody else: a seat is somebody's own
purchase. None is kept as sent; the invoice and the credit note are the
documents. Payloads carry an offer name and a link, and no card, provider
message or amount (§31).

The payment layer says a failure happened through a second port,
`InvoicePaymentFailed`, beside `InvoicePaid` and for the same reason: it must not
learn what a purchase is. Only a purchase in flight is told — a renewal or a debt
is the collection schedule's to speak for.

### Somebody in arrears is sent to the invoice they owe

`Sales::order` refuses `SEAT_IN_ARREARS`, naming `past_due_invoice_id`, where it
used to sell. The check now agrees with the index, as the hold already did.

### Stranded orders are ended by the schedule: `sweep.orders`, hourly

A new periodic job (`SettleStrandedOrders`), declared in `Schedule::PERIODIC`:

- **A held order is refunded.** Through `Payments::refund` — the credit note
  planned before the provider is asked and written on the refund's own
  transaction (ADR-058) — then the order is closed (`ORDER_CANCELLED`, reason
  `REFUNDED_WHILE_SEATED`) and its buyer told, the last two together. A pass that
  stopped between the refund and the closing is recognised by the refund chain's
  own refusal, so nothing is refunded twice. The session reads `REFUNDED`, a new
  derived status: cancelled *and the money given back* is not what `CANCELLED`
  says.
- **An abandoned checkout is given up** after the product's own delay
  (`product_configuration`, key `checkout`, `abandon_after_hours`, **48 by
  default**, 1 to 720), through `Checkout::cancel` — the buyer's own door, so the
  invoice is cancelled with its VAT reversal exactly as it would be by hand.
  **Never while money may still arrive**: an attempt still open at the provider
  leaves the checkout for the next pass.

And `Checkout::open` now returns the session when the provider cannot be reached
— awaiting, payable, with no attempt — instead of an error with nothing to come
back to. A rule's refusal (no provider configured) is still an error.

### A period invoiced ahead and cancelled before it begins is not owed

`CancellationPolicy` decides against the end of the period **actually running**
(`Subscription::runningPeriodEndAt`). When the cancellation takes effect at or
before the start of the period invoiced ahead:

```text
invoice unpaid    cancelled, with the reversal of the VAT it declared
invoice paid      refunded in full, with its credit note (the refund chain)
the row           put back on the period running; the entitlements end with it
```

In two moments, because a provider cannot be asked inside a transaction and
unasked afterwards (`UnstartedPeriodRelease`): the refund, or the abandoning of
an attempt still open, runs first and on its own; the invoice's cancellation and
the row's return run **on the cancellation's own transaction**. A cancellation
that fails after a refund is retried and finds nothing left to return.

The cancellation's event and its response carry `unstarted_period` — which
period, which invoice, what became of it. A cancellation deferred past that
period (to the end of a commitment or a term) leaves it alone: it will run, and
is owed.

`RENEWED` and a change applied at a boundary now record where the period they
left began, and the term and commitment a term roll replaced, so the row can be
put back exactly. A roll from before this date is rebuilt from the billing
period.

### A notice that cannot be honoured says the truth instead

Past the last day a mailed notice still counts (`notice_days` before the day to
refuse by), the job raises `subscription.term_ending` — *your subscription ends
on D; it does not renew by itself this time* — instead of the renewal notice.
Kept as sent. A term has one or the other and never both.

And an offer whose term can never roll is refused at publication,
`OFFER_TERM_CANNOT_RENEW`: an `AUTO_RENEW` term shorter than its notice plus the
**longest** renewal lead a product may choose (30 days). Measured against the
longest and not today's, because the lead is a setting and a published version
is frozen.

### The read says what happens next

`GET /subscription` answers `outlook` beside `seat`: the end of the period
running, the period invoiced ahead with its invoice, whether it continues
(`ENDS`, `RENEWS_PERIOD`, `RENEWS_TERM`), when it ends, the day a term renews and
the last day to refuse it, and what cancelling now would do. Derived on read and
never stored. The screen renders these and computes none.

## Consequences

- One hour is the longest a held order waits for its refund; the buyer is told
  when it is made, and the checkout screen says so in the meantime.
- A checkout left open for two days disappears, document and all. A product that
  takes bank transfers for purchases should lengthen `abandon_after_hours`.
- `Subscriptions` takes one more collaborator (`UnstartedPeriodRelease`), and
  `PaymentSettlement` one more method (`failed`).
- **Not done, and said so:** a renewal notice raised in time whose mail then
  fails or is suppressed still ends the term with nothing further said — the
  read does not look at deliveries. A period rolled by a *change of offer* and
  then cancelled before it begins is ended at the boundary and its invoice
  withdrawn, but the row keeps naming the offer it was about to move to.
  Month-end period drift (a start on the 29th–31st) is untouched: changing the
  arithmetic changes dates customers were given, which is the operator's call.

## Proof

`CheckoutSessionTest` — the purchaser is told, the refusal names the invoice
owed, a held order is refunded through `JobRunner::runOnce`, an abandoned
checkout is given up after the product's delay and never while money may still
arrive, a provider outage leaves a session. `RenewalPassTest` — cancelling
before a period invoiced ahead begins (unpaid, paid, deferred), and what the read
says next. `RenewalNoticeTest::testANoticeThatCanNoLongerBeHonouredSaysTheTermEnds`,
`OfferAuthoringTest::testATermTooShortForItsOwnNoticeCannotBePublishedAsRenewing`.
Frontend: `SubscriptionScreen.test.tsx` ("what happens next"),
`CheckoutScreen.test.tsx` (refunded).
