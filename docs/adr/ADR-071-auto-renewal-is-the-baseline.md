# ADR-071 — Auto-renewal is the baseline

**Status:** accepted (2026-10-29)
**Relates to:** ADR-068 (a paid period renews itself), ADR-070 (the customer is
asked before the period), §13.1 (terms, commitment, cancellation), §27.1
(notifications), R11

## Context

ADR-068 made a paid period renew itself and bill for it, and left two things
for a person to decide:

- **Whether renewal happens at all.** `RenewalPolicy` was off unless a product's
  configuration said `{"automatic": true}`. A deployment that never opened the
  console renewed nothing, so every subscription ended at its first paid period.
- **Whether a term rolls.** At the end of a term, `renew()` answered
  `TERM_REACHED` and nothing rolled. R11 held that open on the ground that tacit
  renewal needs a prior notice whose deadline nobody had checked.

The operator decided on 2026-10-29: **auto-renewal is the baseline.** A
subscription is sold as continuing, so silence means it continues, and stopping
it is the decision.

## Decision

**Period renewal is on unless switched off.** An absent `renewal` document
renews, seven days ahead. Only a strict `"automatic": false` switches it off.
A part of the document nobody can read is treated as unsaid, so it leaves the
baseline in place.

**At the end of a term, an `AUTO_RENEW` offer rolls into its next term.** The
term's first period, the new `term_ends_at` and the new `commitment_ends_at` move
in one statement, on the same transaction as the invoice. Both dates are counted
from the end of the term that is ending. The price is still the version the
subscription snapshotted (ADR-033). An offer sold `ENDS_AT_TERM` still ends.

**The notice is the precondition, not a courtesy.** The term rolls only once
`subscription.renewal_notice` for that term was raised at least `notice_days`
before. The check reads the notification row itself, with its rendered body
kept, so "did we tell them?" and "may we renew?" are one fact. If the notice is
missing or too recent, the answer is `TERM_RENEWAL_NOT_NOTICED`, and the next
pass asks again.

**A commitment is never re-armed without a notice.** If the terms commit the
customer but promise no notice (`notice_days = 0`), the term never rolls by
itself. If the terms commit to nothing, the term rolls freely, because the
customer can leave the next day.

**The notice goes out early enough for the renewal to find it.** A term rolls
with its last period, up to a product's renewal lead before it ends. So the
notice window opens `notice_days` plus the longest lead (30 days) before the
term end. Telling the customer early is the direction a notice may err in.

**The notice has words of its own**, in every language: the date, that the term
renews on the same conditions unless cancelled, and how to stop it. Before this,
it went out as a type name and payload lines.

## Consequences

- An upgrading deployment starts renewing immediately, for every product that
  has not switched renewal off. That is the decision, made on purpose.
- Declining is still possible. A cancellation asked for during the notice window
  dates the end at the term (`AT_TERM` or `AT_COMMITMENT_END`), and renewal never
  rolls past a cancellation that is due.
- The event history records a rolled term on the `RENEWED` event:
  `term_renewed`, plus the new dates.
- **R11 is narrowed to the figures.** The mechanism is complete. What has still
  not been checked is whether each offer's `notice_days` meets the deadline of
  every member state it sells into. The offer's figure is the one applied.
