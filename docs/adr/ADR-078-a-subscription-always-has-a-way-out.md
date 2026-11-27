# ADR-078 — A subscription always has a way out

**Status:** accepted (2026-11-27)
**Amends:** ADR-072 (committed arrears and the sweep), ADR-068 (renewal reads what
has not lapsed), the order chain of §20 (a completed order names its invoice)
**Relates to:** ADR-059 (the free period), ADR-060 (arrears suspend coverage),
ADR-067 (a handler is not work until something enqueues it)

## Context

An audit of the whole cycle — subscribe, renew, end, change — on 2026-11-27 found
four states a subscription could enter and never leave. None was reachable on the
nominal path, which is why every test passed; each needed two rules to meet.

1. **A free offer that renews, bought through the checkout.** The order chain
   raised a €0 invoice so that `orders_completed_is_traceable` would let the order
   complete. `Checkout::open` then asked the provider to authorize a payment of
   nothing (a 500 with the stub). Nothing can pay an invoice of zero, so it stayed
   `ISSUED`, the collection pass read it as overdue, and after the grace the free
   seat was `PAST_DUE` — suspended for owing nothing.
2. **A scheduled change onto the free period.** The free period is `CUSTOM`: never
   billed, so no billing period to count. `applyPendingChange` computed its end
   from the billing period, got none, and wrote `current_period_end = NULL` with
   entitlements valid for ever. Neither the renewal nor the sweep reads such a
   row. A free period that never finishes.
3. **Arrears under commitment that the customer lawfully cancelled.** The renewal
   refuses to roll past a cancellation that is due; the sweep (ADR-072) refuses
   committed arrears. Neither took the row. `PAST_DUE` for ever, chased, and
   holding `subscriptions_one_active_seat_per_person` so its holder could not
   subscribe again.
4. **A debt behind two hundred older ones.** The collection pass read one page,
   oldest debt first, and a debt chased to its last step never leaves that order.
   Everything newer was never declared and never told — the bug the renewal and
   the notices were already given pages for.

And one smaller: `expire()` matched `ACTIVE` alone but wrote its `EXPIRED` event
regardless, so arrears reaching a term that does not roll stayed suspended while
their history said, once a pass, that they had ended.

## Decision

**Nothing to collect raises no document — on the first period as on the next.**
`OrderFulfilment::invoice()` answers `invoice_id: null` for an order of zero, and
the order completes at once without passing through `AWAITING_PAYMENT`. This is
the rule the renewal has followed since 2026-10-29, the one the early-termination
charge and the change of offer state, and the one the contract already described
(`Order.invoice_id`: "null only for an order with nothing to collect"). The
constraint now says what it meant: a completed order names its invoice **when
there was something to collect**. The terms are still checked — free or not, an
order is not completed on an offer withdrawn since it was placed.

**And a document of zero is nobody's debt.** `overdue()` reads only invoices with
`gross_minor_units > 0`. Deployments hold €0 invoices from before this date, so
the read refuses them rather than trusting none exist, and the migration reopens
every subscription whose arrears name one (`ARREARS_CLEARED`, reason
`NOTHING_WAS_OWED`).

**A free period reached by a scheduled change ends like one taken at the door.**
`applyPendingChange` takes the product's `FreemiumPeriod` and counts its days from
the boundary the change happens at. The renewal pass reads it from
`product_configuration`, where the door reads it. With neither a billing period
nor a configured free period the change is **refused** — `CHANGE_HAS_NO_PERIOD` —
and the subscription lapses at its period's end like any other that was not
renewed. Fail closed: time given away cannot be taken back.

**A lawful cancellation is not undone by a debt.** A commitment binds somebody to
keep paying; it does not override a cancellation the policy accepted. So:

```text
renewal   never reads a row whose cancel_effective_at <= now()
sweep     ends committed arrears whose cancel_effective_at <= now()
```

The two conditions are each other's negation, so the two jobs still never hold
the same row (ADR-072's invariant). The event says which ending it was
(`EXPIRED`, reason `CANCELLATION_DUE_IN_ARREARS`). **What was owed is still
owed**: the invoices stay `ISSUED`. They are no longer chased by the collection
pass, which reads live subscriptions only — the same as any other subscription
that ended owing money, and a decision this ADR does not reopen.

A cancellation dated later (deferred to the commitment's end) is not due, so the
row stays the renewal's until that date.

**The collection pass pages**, `PAGES × BATCH`, by offset over a stable order.
Nothing it does removes a row from that order except a payment that settles at
once, and the debt that skips is read by the next pass.

**`expire()` ends arrears too, and records only a transition that happened.**

## Consequences

- A free renewing offer can be sold through the same door as any other, with no
  document and no payment at any period.
- `Subscriptions::renew()` takes an optional `FreemiumPeriod`. By hand, without
  it, a change onto the free period is refused rather than guessed.
- A subscription that ends in arrears leaves an unpaid invoice behind it. Whether
  such debts should be chased after the contract ends is not decided here.
- Not addressed, and written down so it is not forgotten: a subscription in
  arrears under commitment whose term simply does not roll (renewal switched off,
  notice not honoured) is still "stuck until an operator ends it", which ADR-072
  accepted.

## Proof

`CheckoutSessionTest::testAFreeOfferThatRenewsCompletesWithNoDocumentAndNoPayment`,
`SalesChainTest::testAnOrderWithNothingToCollectNeedsNoPayment`,
`PastDueTest::testAnInvoiceOfNothingIsNeverChased`,
`PastDueTest::testADebtBeyondTheFirstPageIsStillDeclaredAndChased`,
`FreemiumTest::testAFreePeriodReachedByAScheduledChangeEndsLikeOneTakenAtTheDoor`,
`FreemiumTest::testAScheduledChangeOntoAFreePeriodOfNoKnownLengthIsRefused`,
`RenewalPassTest::testCommittedArrearsTheCustomerCancelledEndWhenTheirDateComes`,
`RenewalPassTest::testCommittedArrearsCancelledForLaterAreStillTheRenewals`,
`RenewalPassTest::testArrearsReachingATermThatDoesNotRollEndOnceAndForReal`.
Each fails on the code as it stood.
