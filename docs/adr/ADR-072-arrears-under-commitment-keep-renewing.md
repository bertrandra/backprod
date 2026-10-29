# ADR-072 — Arrears under commitment keep renewing

**Status:** accepted (2026-10-29)
**Supersedes in part:** ADR-068 ("Arrears are not renewed")
**Relates to:** ADR-060 (arrears suspend coverage), ADR-068 (a paid period renews
itself), ADR-071 (auto-renewal is the baseline), §13.1 (commitment)

## Context

ADR-068 said a `PAST_DUE` subscription is never renewed, and the sweep
(`sweep.subscriptions`) expired every `PAST_DUE` row whose period had lapsed. So
one missed payment ended a 24-month commitment at the end of the current period,
and paying later restored nothing: the row was `EXPIRED`. Arrears had become an
exit from a contract — the exact thing `subscriptions_one_active_seat_per_person`
widening to `PAST_DUE` was meant to rule out ("being in arrears is not an exit").

The operator decided on 2026-10-29: **arrears under commitment are not an exit.**

## Decision

**Under a commitment, a subscription in arrears renews like an active one.**
`PAST_DUE` with `commitment_ends_at` after the end of the period being renewed:
the period rolls, the next period is invoiced through `RenewalCharge` (due the
day it starts, like any renewal), a change due at that boundary is applied, and
the entitlement rows extend. **The status stays `PAST_DUE`**: renewing is the
contract running, not the debt being paid, so coverage stays `IN_ARREARS` and the
workshop stays shut. One rule in two spellings that say the same thing —
`Subscription::keepsRenewingInArrears()` and the repository's
`COMMITTED_ARREARS`.

**Without a commitment, or once it has ended, arrears are not renewed** — ADR-068's
reasoning holds there: the customer is free to leave, and a further invoice
against a suspended service compounds the debt. The sweep expires such a row once
its period lapses, as before. A commitment ending exactly at the period end being
renewed counts as ended.

**The sweep leaves committed arrears alone, and the renewal catches them up.** The
two jobs must never disagree about the same row. Renewal runs before the period
ends, so a committed `PAST_DUE` row should never lapse; if cron was down and it
did, expiring it would end the commitment by accident. So `expireLapsed()` skips
it, and `dueForRenewal()` returns it even once lapsed (the only rows it reads past
their period end), renewing from where the period ended, never from now. They
share no row: the sweep takes exactly what the renewal will not.

This is stricter than for `ACTIVE` rows, which ADR-068 still lets lapse and expire
after a long outage. Deliberately: an expired row can be bought again, while a
debt cannot be unwound by the clock, and "a pass never cancels" (ADR-060) is the
rule this follows. The cost is stated: a committed row in arrears that the pass
refuses for another reason — renewal switched off for its product, a term that
may not roll — stays `PAST_DUE`, covering nobody and still chased, until an
operator ends it. Ending it is a decision, not something cron does.

**Paying reopens only when nothing else is overdue.** The column names one debt
(`past_due_invoice_id`), and committed arrears can now owe several. When the
named invoice is paid and another invoice of the same subscription is unpaid at
least the product's grace (`DunningSchedule::graceDays()`, the collection pass's
own first step) after falling due, the suspension is **handed to the oldest such
invoice**: still `PAST_DUE`, `past_due_since` kept — it is the same suspension —
and an `ARREARS_DECLARED` event naming both the new debt and the settled one.
Otherwise it reopens as before (`ARREARS_CLEARED`). A debt still inside the grace
holds nothing; the collection pass declares it if it is still unpaid afterwards.
Paying an invoice the column does not name lifts nothing, as before.
`subscriptions_arrears_name_their_debt` holds throughout: the date and the invoice
move together or not at all.

## Consequences

- A committed customer who stops paying keeps receiving one invoice per period
  until the commitment ends, each chased by the dunning schedule; paying them
  all reopens the workshop for the period they are in.
- After the commitment ends, the next renewal refuses (`RENEWAL_IN_ARREARS` if
  asked by hand; the pass never selects it) and the sweep expires the row at the
  end of its period.
- No migration: the status set, the constraints and the event types are unchanged.
