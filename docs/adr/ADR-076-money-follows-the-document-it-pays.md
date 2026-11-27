# ADR-076 — Money follows the document it pays

**Status:** accepted (2026-11-27)
**Relates to:** ADR-058 (a refund carries its credit note), ADR-060 (arrears
suspend coverage), ADR-070 (the customer is asked to pay before the period),
spec §3 (a change of plan, prorated), §13.1

## Context

An audit of the subscription cycle on 2026-11-27 found three ways money and the
document it belonged to came apart. None was reachable while every invoice was
paid the moment it was raised, which is the only way the tests ever paid one.

**A cancelled checkout could still be charged.** `Checkout::cancel` asked one
thing — *has the latest payment settled?* — and voided the invoice under an
attempt that could still collect: a 3-D Secure challenge left open, a debit in
processing. When that money arrived, `InvoiceSettlement` found a `CANCELLED`
invoice it was not allowed to settle and returned. The customer was charged, the
invoice and the order were cancelled, no subscription started, and the screen
had said *nothing was charged*. The comment on that code called it "an anomaly
an operator should see"; no screen showed it.

**The credit of an upgrade was not tied to the period it cut.** It was a share
of *the latest settled payment against any invoice of the subscription*, on the
reasoning that every change raises its own invoice and resets the anchor. But a
renewal invoice is raised seven days ahead and due when the period starts
(ADR-070), and an upgrade's own invoice is payable on receipt, not collected by
the change. In both cases the newest *settled* payment is one period behind:

- in the renewal lead window, the row had already been rolled into a period that
  had not started; "before the start" clamps to everything, so a period
  twenty-seven days consumed was refunded in full, and the renewal invoice for
  the plan being left stayed owed and chased;
- a second change before the first change's invoice was paid prorated the old
  payment over the new period and refunded the rest of it.

**A retried change refunded twice, and two at once both moved the row.** The
credit commits before the plan moves, on purpose. The comment said a retry
"finds nothing left to credit"; it found `amount − refunded` still returnable and
sent the same share again. And the `UPDATE` that moved the plan was `WHERE id =
:id` — two simultaneous requests both passed `ALREADY_ON_OFFER` on their own
read and raised an invoice each.

## Decision

**1. An invoice is not voided under a payment that can still collect.**
`Payments::abandonAttempts()` cancels every live attempt *at the provider* first.
One the provider confirms dead is recorded abandoned; one it cannot stop refuses
the act with `PAYMENT_STILL_IN_FLIGHT`, because that money is coming and the
purchase it pays must still be there. `Checkout::cancel` calls it before it
cancels the invoice, and so does a change of plan before it withdraws a renewal
invoice (below). The provider is the lock: no read of ours can be raced once it
has said the attempt is dead.

**2. Money that settles against a void document goes back by itself.** For what
(1) cannot reach — an invoice voided by another path, a provider that confirmed
a cancel and collected anyway — `payment.return_unowed` runs hourly, finds settled
payments whose invoice is `CANCELLED` and whose money has not gone back, and
refunds them in full. **No credit note is raised**: the sale was undone, and its
VAT reversed, when the invoice was cancelled; there is no declared sale left to
correct, only somebody's money. The refund row and its ledger line are the
record. A chargeback is still not this, for ADR-058's reason.

**3. A proration credit is a share of the invoice whose period is being cut.**
`ChangeCredit::collectedFor()` reads the subscription's two newest period
invoices and decides:

| The newest period invoice is… | The credit is a share of… | And… |
|---|---|---|
| paid | its settled payment, over the row's period | — |
| unpaid, its period started | nothing | the change still happens; the reason says the invoice remains due |
| unpaid, **raised ahead** (period not started) | the invoice *before* it, over that invoice's own period up to where the row's begins | the invoice raised ahead is **withdrawn** |

The middle row keeps spec §3.3: a period nobody paid for has no unconsumed value,
and refusing would trap somebody on a plan nobody is paying for. The last row is
the decision: **an upgrade in the renewal lead window withdraws the renewal
invoice of the plan being left** — attempts abandoned, invoice cancelled, VAT
reversed in the period the cancellation is made in — rather than refusing the
upgrade. Refusing would close the door a quarter of every month; leaving the
invoice would bill a period that will never run on those terms.

A renewal invoice already *paid* in that window is the period's invoice like any
other: the period has not started, so all of it comes back. The few days left of
the period still running are not credited as well. That is in the platform's
favour by at most the lead, and it keeps one refund per change.

**4. A suspended subscription pays before it changes plan.** `PAST_DUE` refuses
an immediate change with its own code, `CHANGE_AWAITS_PAYMENT` (rule
`change.awaits_payment`, so the preview says it before the click), naming the
invoice. `CHANGE_NOT_PERMITTED` reads as "this cannot be done"; this one is
answered by a card, the distinction ADR-060 drew for the workshop.

**5. A proration is made once per period cut.** `refunds.purpose_key`, unique
among refunds that did not fail (`refunds_once_per_purpose`). A proration names
`change:{subscription}:{invoice}`; `PaymentRepository::recordRefund()` reads the
name under the invoice's lock and answers the earlier refund without asking the
provider again. The index is the rule; the read is only what lets the answer be
the earlier refund rather than a violation.

**6. The immediate change is written against the row it was decided from.** The
`UPDATE` is conditioned on the version it leaves, the period end it cuts and the
status it read. Zero rows is `SUBSCRIPTION_CHANGED` (409) — a second request, or
a renewal that rolled the period in between.

## Consequences

- `PaymentRepository::latestSettledForSubscription()` is gone;
  `latestSettledForInvoice()` replaces it. `InvoiceRepository::periodInvoicesOf()`
  is new.
- A change whose charge is zero raises no invoice, so a later change finds the
  same period invoice and — by (5) — makes no second credit against it. That is
  conservative, and it is the direction a mistake should fall in.
- The credit's proration clock and the refund actually returned can differ by a
  retry's delay: the decision reports the share at the retry, the refund is the
  one made first. The refund is the fact.
- Not decided here: a *deferred* move down requested while suspended. It moves
  no money and takes effect at a boundary the arrears rules already govern.

## Proof

`ProratedUpgradeTest` — the lead window, the retried move, a second move before
the first is paid, the suspended subscription, the stale row.
`CheckoutSessionTest` — the abandoned attempt and its late success, the attempt
the provider cannot stop, and money on a void invoice going back through
`JobRunner::runOnce()`, which is what cron calls.
